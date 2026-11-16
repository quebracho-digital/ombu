<?php

declare(strict_types=1);

namespace Drupal\ombu\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\ombu\Stop;
use Drupal\x402_pay\Stop as PaymentsStop;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stops and resumes Ombú, and edits the templates of its blog posts.
 */
final class StopForm extends ConfigFormBase {

  private const KEYS = [
    'category' => 'Category',
    'stop_title' => 'Title of a stop',
    'stop_body' => 'Body of a stop',
    'resume_title' => 'Title of a resume',
    'resume_body' => 'Body of a resume',
    'no_reason' => 'The reason, when none is given',
  ];

  /**
   * The switch.
   */
  protected Stop $stop;

  /**
   * The payments' switch, for its state.
   */
  protected PaymentsStop $payments;

  /**
   * The site's languages: a reason and a set of templates each.
   */
  protected LanguageManagerInterface $languages;

  /**
   * Formats the time of the stop.
   */
  protected DateFormatterInterface $dates;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->stop = $container->get('ombu.stop');
    $form->payments = $container->get('x402_pay.stop');
    $form->languages = $container->get('language_manager');
    $form->dates = $container->get('date.formatter');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ombu_stop';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ombu.stop'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $stopped = $this->stop->engaged();
    $note = $this->stop->note();
    $default = $this->languages->getDefaultLanguage()->getId();
    $form['state'] = [
      '#type' => 'item',
      '#title' => $this->t('State'),
      '#plain_text' => $stopped
        ? $this->t('Stopped (read-only) since @time. Reason: @why', [
          '@time' => $note ? $this->dates->format($note['time'], 'short') : '?',
          '@why' => $note && $note['why'] !== '' ? $note['why'] : $this->t('none given'),
        ])
        : ($this->payments->engaged() ? $this->t('Running, with payments paused on their own.') : $this->t('Running.')),
    ];
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Stopping pauses payments first, then makes the whole site read-only: writes and paid calls answer a temporary error (503), and no page shows a wallet. Resuming goes the other way. Each one publishes a blog post from the templates below. To pause payments alone, use the x402 settings.') . '</p>',
    ];
    $form['why'] = [
      '#type' => 'textfield',
      '#title' => $stopped ? $this->t('A note for the post (public)') : $this->t('Reason (public)'),
      '#description' => $this->t('Published in the blog post, as plain text.'),
      '#maxlength' => 500,
    ];
    $form['reasons'] = ['#type' => 'details', '#title' => $this->t('In other languages'), '#tree' => TRUE];
    $form['templates'] = ['#type' => 'details', '#title' => $this->t('Templates of the blog post'), '#tree' => TRUE];
    $form['templates']['post'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Publish a blog post at each stop and each resume'),
      '#default_value' => (bool) $this->config('ombu.stop')->get('post'),
    ];
    $templates = $this->config('ombu.stop')->get('templates') ?: [];
    foreach ($this->languages->getLanguages() as $langcode => $language) {
      if ($langcode !== $default) {
        $form['reasons'][$langcode] = ['#type' => 'textfield', '#title' => $language->getName(), '#maxlength' => 500];
      }
      $form['templates'][$langcode] = ['#type' => 'details', '#title' => $language->getName()];
      foreach (self::KEYS as $key => $label) {
        $form['templates'][$langcode][$key] = [
          '#type' => str_ends_with($key, '_body') ? 'textarea' : 'textfield',
          // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
          '#title' => $this->t($label),
          '#default_value' => $templates[$langcode][$key] ?? '',
          '#maxlength' => str_ends_with($key, '_body') ? NULL : 255,
          '#description' => str_ends_with($key, '_body') ? $this->t('HTML. {reason} and {date} are replaced.') : NULL,
        ];
      }
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['switch'] = [
      '#type' => 'submit',
      '#value' => $stopped ? $this->t('Resume Ombú') : $this->t('Stop Ombú'),
      '#button_type' => $stopped ? 'primary' : 'danger',
      '#submit' => ['::save', '::flip'],
    ];
    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save the templates'),
      '#submit' => ['::save'],
    ];
    return $form;
  }

  /**
   * Saves the templates.
   */
  public function save(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValue('templates');
    $templates = [];
    foreach (array_keys($this->languages->getLanguages()) as $langcode) {
      foreach (array_keys(self::KEYS) as $key) {
        $templates[$langcode][$key] = (string) ($values[$langcode][$key] ?? '');
      }
      // A language left empty falls back to English: not stored.
      if (implode('', $templates[$langcode]) === '') {
        unset($templates[$langcode]);
      }
    }
    $this->config('ombu.stop')->set('post', (bool) $values['post'])->set('templates', $templates)->save();
    $this->messenger()->addStatus($this->t('The templates are saved.'));
  }

  /**
   * Stops, or resumes.
   */
  public function flip(array &$form, FormStateInterface $form_state): void {
    $why = trim((string) $form_state->getValue('why'));
    $reasons = array_filter(array_map('trim', (array) $form_state->getValue('reasons')));
    $done = $this->stop->engaged() ? $this->stop->release($why, $reasons) : $this->stop->engage($why, $reasons);
    foreach (array_filter($done['payments'] + ['site' => $done['site']]) as $switch => $error) {
      $this->messenger()->addError($this->t('@switch: @error', ['@switch' => $switch, '@error' => $error]));
    }
    $this->messenger()->addStatus($this->stop->engaged() ? $this->t('Ombú is stopped: payments paused, site read-only.') : $this->t('Ombú is running again.'));
    if ($done['post']) {
      $this->messenger()->addStatus($this->t('Blog post published: <a href=":url">node @id</a>.', [
        ':url' => Url::fromRoute('entity.node.canonical', ['node' => $done['post']])->toString(),
        '@id' => $done['post'],
      ]));
    }
  }

}

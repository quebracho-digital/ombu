<?php

declare(strict_types=1);

namespace Drupal\ombu\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\ombu\Embeddings;
use Drupal\ombu\Similar;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * How Similar weighs what two listings share.
 */
final class SimilarSettingsForm extends ConfigFormBase {

  /**
   * Lists the listing's taxonomy fields.
   */
  private EntityFieldManagerInterface $fieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->fieldManager = $container->get('entity_field.manager');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ombu_similar_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ombu.similar'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = ($this->config('ombu.similar')->get() ?: []) + Similar::DEFAULTS;
    $weight = fn (\Stringable|string $title, $value, \Stringable|string $description = '') => [
      '#type' => 'number',
      '#title' => $title,
      '#description' => $description,
      '#default_value' => $value,
      '#min' => 0,
      '#step' => 0.1,
      '#required' => TRUE,
    ];
    $form['terms'] = $weight($this->t('Shared terms'), $settings['terms'], $this->t('Category, tags and the fields below, rare terms counting most.'));
    $form['fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Term fields'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('Weight of each taxonomy field of a listing; 0 leaves it out.'),
    ];
    foreach ($this->fieldManager->getFieldDefinitions('commerce_product', 'listing') as $name => $field) {
      if ($field instanceof FieldConfig && $field->getType() === 'entity_reference' && $field->getSetting('target_type') === 'taxonomy_term') {
        $form['fields'][$name] = $weight((string) $field->getLabel(), $settings['fields'][$name] ?? 0);
      }
    }
    $form['vector'] = $weight($this->t('Similar texts'), $settings['vector'], Embeddings::available()
      ? $this->t('Cosine similarity of the listings’ embeddings. Listings without one count by terms only.')
      : $this->t('Needs MariaDB 11.7+: off on this site.'));
    $form['per_provider'] = [
      '#type' => 'number',
      '#title' => $this->t('At most per provider'),
      '#default_value' => $settings['per_provider'],
      '#min' => 1,
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ombu.similar')
      ->set('terms', (float) $form_state->getValue('terms'))
      ->set('vector', (float) $form_state->getValue('vector'))
      ->set('per_provider', (int) $form_state->getValue('per_provider'))
      ->set('fields', array_map('floatval', $form_state->getValue('fields') ?? []))
      ->save();
    // Views cache their results by the index's tag.
    Cache::invalidateTags(['search_api_list:listings']);
    parent::submitForm($form, $form_state);
  }

}

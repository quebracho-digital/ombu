<?php

declare(strict_types=1);

namespace Drupal\ombu;

/**
 * Why a review is not backed by a purchase; the message is for the reviewer.
 */
final class ReviewRefusedException extends \RuntimeException {}

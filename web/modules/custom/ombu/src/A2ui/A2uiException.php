<?php

declare(strict_types=1);

namespace Drupal\ombu\A2ui;

/**
 * An A2UI payload that cannot be taken: malformed, too big or not supported.
 *
 * The message is for logs and developers, never markup: a seller wrote the
 * payload, so nothing in it is trusted, and the exception text can quote it.
 */
final class A2uiException extends \RuntimeException {
}

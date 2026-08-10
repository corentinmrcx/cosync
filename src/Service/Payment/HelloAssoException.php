<?php declare(strict_types=1);

namespace App\Service\Payment;

/** Échec de dialogue avec l'API HelloAsso : configuration manquante, réseau, ou réponse inattendue. */
final class HelloAssoException extends \RuntimeException {}

<?php
/**
 * Compatibility facade — same class as portable Domain CryptoException (A1).
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

// Same identity so `catch ( CryptoException )` matches Domain throws.
class_alias(
	\RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\CryptoException::class,
	__NAMESPACE__ . '\\CryptoException'
);

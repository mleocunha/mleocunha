<?php
/**
 * Compatibility facade — delegates to portable Domain crypto (A1).
 *
 * @package RelataSoft\SecureElectionSuite\Crypto
 */

namespace RelataSoft\SecureElectionSuite\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter #1 alias for Domain crypto class ElGamalKeyPair.
 */
class ElGamalKeyPair extends \RelataSoft\SecureElectionSuite\Painel\Domain\Crypto\ElGamalKeyPair {
}

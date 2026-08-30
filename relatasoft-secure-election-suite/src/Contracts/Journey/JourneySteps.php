<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\Journey;

/**
 * Canonical elector journey steps (A5).
 *
 * Paths are product language (PT): /voto, /voto/cabina, /voto/obrigado.
 */
final class JourneySteps {

	public const WELCOME   = 'welcome';
	public const BOOTH     = 'booth';
	public const THANK_YOU = 'thank_you';

	/** Relative path segments under the site root (no leading/trailing slash). */
	public const PATHS = array(
		self::WELCOME   => 'voto',
		self::BOOTH     => 'voto/cabina',
		self::THANK_YOU => 'voto/obrigado',
	);

	/** Legacy JourneySettings page keys → step. */
	public const SETTING_KEYS = array(
		'welcome_page_id'   => self::WELCOME,
		'booth_page_id'     => self::BOOTH,
		'thank_you_page_id' => self::THANK_YOU,
	);

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::WELCOME, self::BOOTH, self::THANK_YOU );
	}

	public static function isValid( string $step ): bool {
		return in_array( $step, self::all(), true );
	}

	public static function pathFor( string $step ): string {
		if ( ! isset( self::PATHS[ $step ] ) ) {
			throw new \InvalidArgumentException( 'Unknown journey step: ' . $step );
		}
		return self::PATHS[ $step ];
	}

	public static function fromSettingKey( string $settingKey ): ?string {
		return self::SETTING_KEYS[ $settingKey ] ?? null;
	}

	public static function settingKeyFor( string $step ): string {
		$flip = array_flip( self::SETTING_KEYS );
		if ( ! isset( $flip[ $step ] ) ) {
			throw new \InvalidArgumentException( 'Unknown journey step: ' . $step );
		}
		return $flip[ $step ];
	}
}

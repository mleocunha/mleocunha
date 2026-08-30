<?php
declare(strict_types=1);

/**
 * A5 journey ports — InMemory (no shortcode / page boot).
 *
 * @package RelataSoft\SecureElectionSuite\Tests\Unit\Journey
 */

namespace RelataSoft\SecureElectionSuite\Tests\Unit\Journey;

use PHPUnit\Framework\TestCase;
use RelataSoft\SecureElectionSuite\Painel\Application\Journey\JourneyGateway;
use RelataSoft\SecureElectionSuite\Painel\Contracts\Journey\JourneySteps;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey\InMemoryJourneyPresenter;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey\InMemoryJourneyRouteResolver;
use RelataSoft\SecureElectionSuite\Painel\Infrastructure\Journey\InMemoryJourneyUrlGenerator;

final class JourneyPortsTest extends TestCase {

	private JourneyGateway $gw;

	protected function setUp(): void {
		JourneyGateway::reset();
		$this->gw = JourneyGateway::boot(
			new JourneyGateway(
				new InMemoryJourneyUrlGenerator( 'https://cliente.voto.test' ),
				new InMemoryJourneyRouteResolver(),
				new InMemoryJourneyPresenter(),
			)
		);
	}

	protected function tearDown(): void {
		JourneyGateway::reset();
	}

	public function test_url_generator_paths(): void {
		$this->assertSame( 'voto', $this->gw->urls->basePath() );
		$this->assertSame( 'voto', $this->gw->urls->pathFor( JourneySteps::WELCOME ) );
		$this->assertSame( 'voto/cabina', $this->gw->urls->pathFor( JourneySteps::BOOTH ) );
		$this->assertSame( 'voto/obrigado', $this->gw->urls->pathFor( JourneySteps::THANK_YOU ) );

		$this->assertSame(
			'https://cliente.voto.test/voto/cabina/?election_id=3&round_id=9',
			$this->gw->url( JourneySteps::BOOTH, array( 'election_id' => 3, 'round_id' => 9 ) )
		);
		$this->assertSame(
			'https://cliente.voto.test/voto/obrigado/',
			$this->gw->url( JourneySteps::THANK_YOU )
		);
	}

	public function test_route_resolver_aliases(): void {
		$this->assertSame( JourneySteps::WELCOME, $this->gw->resolve( '/voto/' ) );
		$this->assertSame( JourneySteps::WELCOME, $this->gw->resolve( 'voto/boas-vindas' ) );
		$this->assertSame( JourneySteps::BOOTH, $this->gw->resolve( '/voto/cabina/?election_id=1' ) );
		$this->assertSame( JourneySteps::THANK_YOU, $this->gw->resolve( 'voto/obrigado/' ) );
		$this->assertNull( $this->gw->resolve( '/painel/' ) );
		$this->assertNull( $this->gw->resolve( '/voter-welcome/' ) );
	}

	public function test_presenter_and_setting_key_map(): void {
		$html = $this->gw->render( JourneySteps::BOOTH, array( 'election_id' => 2, 'round_id' => 4 ) );
		$this->assertStringContainsString( 'data-rses-journey="booth"', $html );
		$this->assertStringContainsString( 'data-election="2"', $html );
		$this->assertStringContainsString( 'data-round="4"', $html );

		$this->assertSame( JourneySteps::WELCOME, JourneySteps::fromSettingKey( 'welcome_page_id' ) );
		$this->assertSame( 'booth_page_id', JourneySteps::settingKeyFor( JourneySteps::BOOTH ) );
	}

	public function test_itinerary_without_host_pages(): void {
		$welcome = $this->gw->url( JourneySteps::WELCOME );
		$booth   = $this->gw->url( JourneySteps::BOOTH, array( 'election_id' => 1, 'round_id' => 1 ) );
		$thanks  = $this->gw->url(
			JourneySteps::THANK_YOU,
			array(
				'rses_receipt' => 'abc',
				'election_id'  => 1,
				'round_id'     => 1,
			)
		);

		$this->assertSame( JourneySteps::WELCOME, $this->gw->resolve( parse_url( $welcome, PHP_URL_PATH ) ?: '' ) );
		$this->assertSame( JourneySteps::BOOTH, $this->gw->resolve( parse_url( $booth, PHP_URL_PATH ) ?: '' ) );
		$this->assertSame( JourneySteps::THANK_YOU, $this->gw->resolve( parse_url( $thanks, PHP_URL_PATH ) ?: '' ) );

		$this->assertStringContainsString( 'data-rses-journey="welcome"', $this->gw->render( JourneySteps::WELCOME ) );
		$this->assertStringContainsString( 'data-rses-journey="thank_you"', $this->gw->render( JourneySteps::THANK_YOU ) );
	}

	public function test_gateway_requires_boot(): void {
		JourneyGateway::reset();
		$this->expectException( \RuntimeException::class );
		JourneyGateway::get();
	}
}

<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Identity;

/**
 * Classificação de campos do cadastro (minimização LGPD).
 * Não coleta CPF/endereço/telefone por padrão — sem finalidade eleitoral obrigatória neste módulo.
 *
 * @phpstan-type FieldMeta array{
 *   purpose:string,
 *   sensitivity:string,
 *   storage:string,
 *   encryption:string,
 *   retention:string,
 *   who_read:string,
 *   who_write:string
 * }
 */
final class DataClassification {

	public const PUBLIC_        = 'public';
	public const INTERNAL       = 'internal';
	public const CONFIDENTIAL   = 'confidential';
	public const SENSITIVE      = 'sensitive';
	public const CRYPTO_SECRET  = 'crypto_secret';

	/**
	 * @return array<string,FieldMeta>
	 */
	public static function userFields(): array {
		return array(
			'id' => array(
				'purpose'     => 'Chave interna de diretório',
				'sensitivity' => self::INTERNAL,
				'storage'     => 'users.rows',
				'encryption'  => 'none',
				'retention'   => 'enquanto a conta existir + trilha mínima de auditoria',
				'who_read'    => 'admin/operador autenticado',
				'who_write'   => 'sistema',
			),
			'uuid' => array(
				'purpose'     => 'Identificador estável não sequencial para APIs/exportações',
				'sensitivity' => self::INTERNAL,
				'storage'     => 'users.rows',
				'encryption'  => 'none',
				'retention'   => 'enquanto a conta existir',
				'who_read'    => 'admin',
				'who_write'   => 'sistema',
			),
			'login' => array(
				'purpose'     => 'Identificador de autenticação local',
				'sensitivity' => self::CONFIDENTIAL,
				'storage'     => 'users.rows',
				'encryption'  => 'none',
				'retention'   => 'enquanto a conta existir',
				'who_read'    => 'admin; titular',
				'who_write'   => 'admin (criação)',
			),
			'email' => array(
				'purpose'     => 'Contacto operacional / recuperação (quando habilitada)',
				'sensitivity' => self::CONFIDENTIAL,
				'storage'     => 'users.rows',
				'encryption'  => 'none (TLS em trânsito)',
				'retention'   => 'enquanto a conta existir',
				'who_read'    => 'admin; titular',
				'who_write'   => 'admin; titular (perfil)',
			),
			'displayName' => array(
				'purpose'     => 'Nome de exibição operacional',
				'sensitivity' => self::INTERNAL,
				'storage'     => 'users.rows',
				'encryption'  => 'none',
				'retention'   => 'enquanto a conta existir',
				'who_read'    => 'admin; operadores do nó',
				'who_write'   => 'admin; titular',
			),
			'roles' => array(
				'purpose'     => 'Autorização RBAC por modo E3',
				'sensitivity' => self::INTERNAL,
				'storage'     => 'users.rows',
				'encryption'  => 'none',
				'retention'   => 'enquanto a conta existir + auditoria de alteração',
				'who_read'    => 'admin',
				'who_write'   => 'admin (nunca self-elevate)',
			),
			'status' => array(
				'purpose'     => 'Controlo de acesso / ciclo de vida',
				'sensitivity' => self::INTERNAL,
				'storage'     => 'users.rows',
				'encryption'  => 'none',
				'retention'   => 'enquanto a conta existir + auditoria',
				'who_read'    => 'admin',
				'who_write'   => 'admin',
			),
			'passwordHash' => array(
				'purpose'     => 'Verificador de autenticação local',
				'sensitivity' => self::CRYPTO_SECRET,
				'storage'     => 'users.rows',
				'encryption'  => 'Argon2id / password_hash (não reversível)',
				'retention'   => 'enquanto a conta existir; nunca em logs/UI',
				'who_read'    => 'nenhum (só verify)',
				'who_write'   => 'sistema (setPassword)',
			),
			'federated_subject' => array(
				'purpose'     => 'Vínculo OIDC gov.br (issuer+sub)',
				'sensitivity' => self::CONFIDENTIAL,
				'storage'     => 'federated_identities blob',
				'encryption'  => 'none (TLS)',
				'retention'   => 'enquanto o vínculo existir',
				'who_read'    => 'admin; titular',
				'who_write'   => 'fluxo federado autenticado',
			),
		);
	}
}

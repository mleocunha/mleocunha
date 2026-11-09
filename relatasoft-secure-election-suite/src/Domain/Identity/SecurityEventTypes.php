<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Domain\Identity;

/** Catálogo de eventos de segurança de identidade (standalone). */
final class SecurityEventTypes {

	public const ACCOUNT_CREATED          = 'account.created';
	public const ACCOUNT_STATUS_CHANGED   = 'account.status_changed';
	public const LOGIN_SUCCESS            = 'auth.login_success';
	public const LOGIN_FAILURE            = 'auth.login_failure';
	public const LOGIN_RATE_LIMITED       = 'auth.login_rate_limited';
	public const LOGOUT                   = 'auth.logout';
	public const PASSWORD_CHANGED         = 'auth.password_changed';
	public const PASSWORD_RESET_REQUESTED = 'auth.password_reset_requested';
	public const ROLE_CHANGED             = 'account.role_changed';
	public const SESSION_REVOKED          = 'session.revoked';
	public const SESSIONS_REVOKED_ALL     = 'session.revoked_all';
	public const FEDERATED_LOGIN_START    = 'fed.login_start';
	public const FEDERATED_LOGIN_SUCCESS  = 'fed.login_success';
	public const FEDERATED_LOGIN_FAILURE  = 'fed.login_failure';
	public const FEDERATED_LINK           = 'fed.link';
	public const FEDERATED_UNLINK         = 'fed.unlink';
	public const CERT_VALIDATED           = 'cert.validated';
}

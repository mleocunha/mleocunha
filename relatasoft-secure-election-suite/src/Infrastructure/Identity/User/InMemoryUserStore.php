<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Infrastructure\Identity\User;

use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserDirectory;
use RelataSoft\SecureElectionSuite\Painel\Contracts\User\UserProvider;

/**
 * Shared in-memory user store implementing current-user + directory (tests).
 */
final class InMemoryUserStore implements UserProvider, UserDirectory {

	/** @var array<int,array<string,mixed>> */
	private array $users = array();

	/** @var array<int,array<string,string>> */
	private array $meta = array();

	private int $autoId = 1;
	private int $currentId = 0;

	public function setCurrentUserId(int $id): void {
		$this->currentId = $id;
	}

	public function currentUserId(): int {
		return $this->currentId;
	}

	public function isAuthenticated(): bool {
		return $this->currentId > 0 && isset($this->users[$this->currentId]);
	}

	public function currentRoles(): array {
		$row = $this->users[$this->currentId] ?? null;
		return $row ? array_map('strval', (array) ($row['roles'] ?? array())) : array();
	}

	public function findById(int $id): ?array {
		return isset($this->users[$id]) ? $this->normalize($this->users[$id]) : null;
	}

	public function findByLogin(string $login): ?array {
		foreach ($this->users as $row) {
			if (($row['login'] ?? '') === $login) {
				return $this->normalize($row);
			}
		}
		return null;
	}

	public function findByEmail(string $email): ?array {
		foreach ($this->users as $row) {
			if (($row['email'] ?? '') === $email) {
				return $this->normalize($row);
			}
		}
		return null;
	}

	public function findIdByMeta(string $metaKey, string $metaValue): int {
		foreach ($this->meta as $id => $bag) {
			if (($bag[$metaKey] ?? null) === $metaValue) {
				return (int) $id;
			}
		}
		return 0;
	}

	public function listByRole(string $role, int $offset = 0, int $limit = 0): array {
		$out = array();
		foreach ($this->users as $row) {
			$roles = array_map('strval', (array) ($row['roles'] ?? array()));
			if (in_array($role, $roles, true)) {
				$out[] = $this->normalize($row);
			}
		}
		usort($out, static fn($a, $b) => $a['id'] <=> $b['id']);
		if ($offset > 0) {
			$out = array_slice($out, $offset);
		}
		if ($limit > 0) {
			$out = array_slice($out, 0, $limit);
		}
		return array_values($out);
	}

	public function create(array $data): array {
		$login = (string) ($data['login'] ?? '');
		$email = (string) ($data['email'] ?? '');
		if ('' === $login || '' === $email) {
			return array('ok' => false, 'error' => 'login and email required');
		}
		if (null !== $this->findByLogin($login)) {
			return array('ok' => false, 'error' => 'login exists');
		}
		$id = $this->autoId++;
		$role = (string) ($data['role'] ?? 'subscriber');
		$this->users[$id] = array(
			'id'           => $id,
			'login'        => $login,
			'email'        => $email,
			'displayName'  => (string) ($data['displayName'] ?? $login),
			'firstName'    => (string) ($data['firstName'] ?? ''),
			'lastName'     => (string) ($data['lastName'] ?? ''),
			'roles'        => array($role),
			'passwordHash' => (string) ($data['password'] ?? ''),
		);
		return array('ok' => true, 'id' => $id);
	}

	public function update(int $id, array $data): array {
		if (!isset($this->users[$id])) {
			return array('ok' => false, 'error' => 'not found');
		}
		foreach (array('email' => 'email', 'displayName' => 'displayName', 'firstName' => 'firstName', 'lastName' => 'lastName') as $in => $field) {
			if (array_key_exists($in, $data)) {
				$this->users[$id][$field] = (string) $data[$in];
			}
		}
		if (isset($data['role'])) {
			$this->users[$id]['roles'] = array((string) $data['role']);
		}
		return array('ok' => true);
	}

	public function setPassword(int $id, string $plaintext): void {
		if (isset($this->users[$id])) {
			$this->users[$id]['passwordHash'] = 'plain:' . $plaintext;
		}
	}

	public function setPasswordHash(int $id, string $hashOrPlaintext): void {
		if (isset($this->users[$id])) {
			$this->users[$id]['passwordHash'] = $hashOrPlaintext;
		}
	}

	public function setRole(int $id, string $role): void {
		if (isset($this->users[$id])) {
			$this->users[$id]['roles'] = array($role);
		}
	}

	public function getMeta(int $id, string $key): string {
		return (string) ($this->meta[$id][$key] ?? '');
	}

	public function setMeta(int $id, string $key, string $value): void {
		if (!isset($this->meta[$id])) {
			$this->meta[$id] = array();
		}
		$this->meta[$id][$key] = $value;
	}

	/** @param array<string,mixed> $row */
	private function normalize(array $row): array {
		return array(
			'id'           => (int) $row['id'],
			'login'        => (string) ($row['login'] ?? ''),
			'email'        => (string) ($row['email'] ?? ''),
			'displayName'  => (string) ($row['displayName'] ?? ''),
			'firstName'    => (string) ($row['firstName'] ?? ''),
			'lastName'     => (string) ($row['lastName'] ?? ''),
			'roles'        => array_values(array_map('strval', (array) ($row['roles'] ?? array()))),
			'passwordHash' => (string) ($row['passwordHash'] ?? ''),
		);
	}
}

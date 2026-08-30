<?php
declare(strict_types=1);

namespace RelataSoft\SecureElectionSuite\Painel\Contracts\User;

/**
 * Port: user directory for RSV / autoridades provision (A3).
 *
 * Rows are plain arrays — never host user objects.
 *
 * @phpstan-type UserRow array{
 *   id:int,
 *   login:string,
 *   email:string,
 *   displayName:string,
 *   firstName:string,
 *   lastName:string,
 *   roles:list<string>,
 *   passwordHash:string
 * }
 */
interface UserDirectory {
	/** @return UserRow|null */
	public function findById(int $id): ?array;

	/** @return UserRow|null */
	public function findByLogin(string $login): ?array;

	/** @return UserRow|null */
	public function findByEmail(string $email): ?array;

	public function findIdByMeta(string $metaKey, string $metaValue): int;

	/**
	 * @return list<UserRow>
	 */
	public function listByRole(string $role, int $offset = 0, int $limit = 0): array;

	/** Count directory users with a given role slug. */
	public function countByRole(string $role): int;

	/**
	 * @param array<string,mixed> $data login,email,password,displayName?,firstName?,lastName?,role?
	 * @return array{ok:true,id:int}|array{ok:false,error:string}
	 */
	public function create(array $data): array;

	/**
	 * @param array<string,mixed> $data email?,displayName?,firstName?,lastName?,role?
	 * @return array{ok:true}|array{ok:false,error:string}
	 */
	public function update(int $id, array $data): array;

	public function setPassword(int $id, string $plaintext): void;

	/** Apply a portable password hash (phpass / WP) without re-hashing when already hashed. */
	public function setPasswordHash(int $id, string $hashOrPlaintext): void;

	public function setRole(int $id, string $role): void;

	public function getMeta(int $id, string $key): string;

	public function setMeta(int $id, string $key, string $value): void;
}

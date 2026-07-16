export type OkResult<T> = { ok: true; value: T };
export type ErrResult<E> = { ok: false; error: E };
export type Result<T, E = Error> = OkResult<T> | ErrResult<E>;

export function ok<T>(value: T): OkResult<T> {
  return { ok: true, value };
}

export function err<E>(error: E): ErrResult<E> {
  return { ok: false, error };
}

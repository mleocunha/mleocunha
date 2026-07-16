export type IdempotencyRecord = {
  key: string;
  requestHash: string;
  responseStatus: number;
  responseBody: unknown;
  createdAt: number;
};

export class IdempotencyStore {
  private readonly records = new Map<string, IdempotencyRecord>();
  private readonly ttlMs: number;

  public constructor(ttlMs = 24 * 60 * 60 * 1000) {
    this.ttlMs = ttlMs;
  }

  public get(key: string): IdempotencyRecord | undefined {
    this.evict();
    return this.records.get(key);
  }

  public set(record: IdempotencyRecord): void {
    this.evict();
    this.records.set(record.key, record);
  }

  private evict(): void {
    const now = Date.now();
    for (const [key, record] of this.records) {
      if (now - record.createdAt > this.ttlMs) {
        this.records.delete(key);
      }
    }
  }
}

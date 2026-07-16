/**
 * Secret provider interface for device private keys.
 * Production macOS installs store the Ed25519 private key in the Keychain
 * (service: com.relatasoft.openclaw-bridge). Development uses DEVICE_PRIVATE_KEY.
 * Never write credentials into config files.
 */
export type KeychainReader = {
  getPassword(service: string, account: string): Promise<string | null>;
};

const SERVICE = "com.relatasoft.openclaw-bridge";

/**
 * Attempts optional native keytar without a compile-time dependency.
 */
export async function createKeychainReader(): Promise<KeychainReader> {
  try {
    const dynamicImport = new Function(
      "specifier",
      "return import(specifier)",
    ) as (specifier: string) => Promise<{
      getPassword?: (service: string, account: string) => Promise<string | null>;
      default?: {
        getPassword: (service: string, account: string) => Promise<string | null>;
      };
    }>;
    const mod = await dynamicImport("keytar");
    const api = mod.default ?? mod;
    if (typeof api.getPassword === "function") {
      return {
        getPassword: (service, account) => api.getPassword!(service, account),
      };
    }
  } catch {
    // keytar unavailable (expected on Linux CI / when not installed)
  }
  return {
    async getPassword(): Promise<string | null> {
      return null;
    },
  };
}

export function createKeychainSecretProvider(
  reader: KeychainReader,
  account: string,
): { getDevicePrivateKey(): Promise<string | null> } {
  return {
    async getDevicePrivateKey(): Promise<string | null> {
      return reader.getPassword(SERVICE, account);
    },
  };
}

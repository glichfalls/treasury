export type AccountProvider = 'manual' | 'ibkr' | 'degiro' | 'zkb'

export interface SyncConfigField {
  key: string
  label: string
  placeholder?: string
  hint?: string
  secret?: boolean
}

export interface ProviderDef {
  label: string
  defaultInstitution?: string
  defaultAccountType?: string
  features: {
    sync: boolean
    csvImport: boolean
  }
  syncConfigFields?: SyncConfigField[]
}

export const PROVIDERS: Record<AccountProvider, ProviderDef> = {
  manual: {
    label: 'Manual',
    features: { sync: false, csvImport: false },
  },
  ibkr: {
    label: 'Interactive Brokers',
    defaultInstitution: 'Interactive Brokers',
    defaultAccountType: 'brokerage',
    features: { sync: true, csvImport: true },
    syncConfigFields: [
      {
        key: 'flexToken',
        label: 'Flex Token',
        placeholder: 'Enter your Flex token',
        hint: 'Generate at IBKR → Reports → Flex Queries → Create Token',
        secret: true,
      },
      {
        key: 'flexQueryId',
        label: 'Flex Query ID',
        placeholder: '123456',
        hint: 'The numeric ID of your configured Flex Query',
      },
      {
        key: 'accountCode',
        label: 'Account Code',
        placeholder: 'U1234567',
        hint: 'Optional — only needed if your query covers multiple IBKR accounts',
      },
    ],
  },
  degiro: {
    label: 'DEGIRO',
    defaultInstitution: 'DEGIRO',
    defaultAccountType: 'brokerage',
    features: { sync: false, csvImport: true },
  },
  zkb: {
    label: 'ZKB',
    defaultInstitution: 'ZKB',
    features: { sync: false, csvImport: true },
  },
}

export function providerDef(provider: AccountProvider | null | undefined): ProviderDef {
  return PROVIDERS[(provider ?? 'manual') as AccountProvider] ?? PROVIDERS.manual
}

export const PROVIDER_OPTIONS = Object.entries(PROVIDERS).map(([value, def]) => ({
  value,
  label: def.label,
}))

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/lib/api'

export interface Account {
  id: string
  name: string
  institution: string | null
  type: string
  currency: string
  createdAt: string
  cashMinor: string
  holdingsMinor: string
  balanceMinor: string
  hasOpeningBalance: boolean
}

export interface NewAccount {
  name: string
  institution?: string | null
  type: string
  currency: string
}

export interface Transaction {
  id: string
  accountId: string
  occurredAt: string
  amountMinor: string
  currency: string
  description: string | null
  type: string
  source: string
  category: string | null
  tags: string[]
  assetIsin: string | null
  assetQuantity: string | null
}

export interface NewTransaction {
  occurredAt: string
  amountMinor: string
  currency?: string
  description?: string | null
  category?: string | null
  tags?: string[]
}

export interface TransactionFilters {
  page?: number
  pageSize?: number
  type?: string
  category?: string
  from?: string
  to?: string
  q?: string
}

export interface TransactionPage {
  items: Transaction[]
  total: number
  page: number
  pageSize: number
}

export interface Holding {
  isin: string
  ticker: string | null
  name: string | null
  quantity: string
  priceCurrency: string | null
  priceMinor: string | null
  priceAsOf: string | null
  valueBaseMinor: string | null
  baseCurrency: string
}

export const useAccountsStore = defineStore('accounts', () => {
  const accounts = ref<Account[]>([])
  const loaded = ref(false)

  async function fetchAll() {
    accounts.value = await api.get<Account[]>('/api/accounts')
    loaded.value = true
  }

  async function create(input: NewAccount): Promise<Account> {
    const created = await api.post<Account>('/api/accounts', input)
    accounts.value = [...accounts.value, created]
    return created
  }

  async function update(id: string, input: Partial<NewAccount>): Promise<Account> {
    const updated = await api.patch<Account>(`/api/accounts/${id}`, input)
    accounts.value = accounts.value.map((a) => (a.id === id ? updated : a))
    return updated
  }

  async function remove(id: string) {
    await api.delete(`/api/accounts/${id}`)
    accounts.value = accounts.value.filter((a) => a.id !== id)
  }

  async function fetchTransactions(
    accountId: string,
    filters: TransactionFilters = {},
  ): Promise<TransactionPage> {
    const params = new URLSearchParams()
    params.set('page', String(filters.page ?? 1))
    params.set('pageSize', String(filters.pageSize ?? 25))
    if (filters.type) params.set('type', filters.type)
    if (filters.category) params.set('category', filters.category)
    if (filters.from) params.set('from', filters.from)
    if (filters.to) params.set('to', filters.to)
    if (filters.q) params.set('q', filters.q)
    return api.get<TransactionPage>(`/api/accounts/${accountId}/transactions?${params.toString()}`)
  }

  async function fetchHoldings(accountId: string): Promise<Holding[]> {
    return api.get<Holding[]>(`/api/accounts/${accountId}/holdings`)
  }

  async function deleteTransaction(accountId: string, transactionId: string): Promise<{ cascadedTradeCount: number }> {
    const res = await fetch(`/api/accounts/${accountId}/transactions/${transactionId}`, {
      method: 'DELETE',
      credentials: 'include',
    })
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error((body as { error?: string }).error ?? `Delete failed (${res.status})`)
    }
    return res.json()
  }

  async function addTransaction(accountId: string, input: NewTransaction): Promise<Transaction> {
    const created = await api.post<Transaction>(`/api/accounts/${accountId}/transactions`, input)
    const acc = accounts.value.find((a) => a.id === accountId)
    if (acc) {
      acc.cashMinor = (BigInt(acc.cashMinor) + BigInt(created.amountMinor)).toString()
      acc.balanceMinor = (BigInt(acc.balanceMinor) + BigInt(created.amountMinor)).toString()
    }
    return created
  }

  async function updateTransaction(
    accountId: string,
    transactionId: string,
    input: Partial<NewTransaction & { type: string; category: string | null; tags: string[] }>,
  ): Promise<Transaction> {
    return api.patch<Transaction>(`/api/accounts/${accountId}/transactions/${transactionId}`, input)
  }

  return {
    accounts,
    loaded,
    fetchAll,
    create,
    update,
    remove,
    fetchTransactions,
    fetchHoldings,
    addTransaction,
    updateTransaction,
    deleteTransaction,
  }
})

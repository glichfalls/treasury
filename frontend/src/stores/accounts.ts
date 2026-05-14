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
  assetIsin: string | null
  assetQuantity: string | null
}

export interface NewTransaction {
  occurredAt: string
  amountMinor: string
  currency?: string
  description?: string | null
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

  async function remove(id: string) {
    await api.delete(`/api/accounts/${id}`)
    accounts.value = accounts.value.filter((a) => a.id !== id)
  }

  async function fetchTransactions(accountId: string): Promise<Transaction[]> {
    return api.get<Transaction[]>(`/api/accounts/${accountId}/transactions`)
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

  return { accounts, loaded, fetchAll, create, remove, fetchTransactions, fetchHoldings, addTransaction, deleteTransaction }
})

// TanStack Table module augmentation: lets DataTable.vue read `meta` fields
// off a column definition with proper typing.

import '@tanstack/vue-table'

declare module '@tanstack/vue-table' {
  interface ColumnMeta<TData, TValue> {
    headerClass?: string
    cellClass?: string
    align?: 'left' | 'right'
  }
}

export {}

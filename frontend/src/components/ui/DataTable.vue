<script setup lang="ts" generic="TData">
import { computed, ref } from 'vue'
import {
  FlexRender,
  getCoreRowModel,
  getFilteredRowModel,
  getSortedRowModel,
  useVueTable,
  type ColumnDef,
  type ColumnFiltersState,
  type SortingState,
} from '@tanstack/vue-table'
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/vue'
import { ArrowUp, ArrowDown, ChevronsUpDown, ChevronLeft, ChevronRight, Filter } from 'lucide-vue-next'

const props = withDefaults(
  defineProps<{
    data: TData[]
    columns: ColumnDef<TData, unknown>[]
    /** Show a filter icon in each filterable column's header. Click opens a per-column popover. */
    showFilters?: boolean
    /**
     * When true, DataTable doesn't filter client-side — the parent owns the
     * filter state (server-side filtering, etc.) and is responsible for
     * re-rendering `data`. Pair with #filter-<id> slots.
     */
    manualFiltering?: boolean
    /** Column ids whose filter is currently active (manual mode only). Drives the per-column indicator. */
    activeFilterColumns?: string[]
    /** Empty-state message when data is empty. */
    emptyText?: string
    /** Make rows clickable (cursor + hover). Pair with @row-click. */
    rowClickable?: boolean
    /**
     * Keeps existing rows visible during refetches and overlays a thin animated
     * bar at the top of the card — avoids the table blanking on every filter/sort change.
     */
    loading?: boolean
    /** Current page (1-based). Pagination renders only when `total` is also provided. */
    page?: number
    /** Page size. Required for pagination. */
    pageSize?: number
    /** Total number of rows across all pages (server-side count). */
    total?: number
    /** Page size options shown in the rows-per-page select. */
    pageSizeOptions?: number[]
    /**
     * When true, DataTable doesn't sort client-side — the parent owns the sort
     * state (server-side sort) and is responsible for re-rendering `data`.
     */
    manualSorting?: boolean
    /** Current sort state (manual mode). Pair with @update:sorting. */
    sorting?: SortingState
  }>(),
  {
    showFilters: false,
    manualFiltering: false,
    emptyText: 'No data.',
    rowClickable: false,
    loading: false,
    pageSizeOptions: () => [25, 50, 100],
    manualSorting: false,
  },
)

const emit = defineEmits<{
  'row-click': [TData, MouseEvent]
  'update:page': [number]
  'update:pageSize': [number]
  'update:sorting': [SortingState]
}>()

const showPagination = computed(
  () => props.total !== undefined && props.page !== undefined && props.pageSize !== undefined,
)
const totalPages = computed(() =>
  Math.max(1, Math.ceil((props.total ?? 0) / (props.pageSize ?? 1))),
)
const rangeStart = computed(() => {
  if (!props.total || !props.page || !props.pageSize) return 0
  if (props.total === 0) return 0
  return (props.page - 1) * props.pageSize + 1
})
const rangeEnd = computed(() => {
  if (!props.total || !props.page || !props.pageSize) return 0
  return Math.min(props.page * props.pageSize, props.total)
})

function goToPage(n: number) {
  if (props.page === undefined) return
  const next = Math.min(totalPages.value, Math.max(1, n))
  if (next !== props.page) emit('update:page', next)
}

const internalSorting = ref<SortingState>([])
const columnFilters = ref<ColumnFiltersState>([])

function currentSorting(): SortingState {
  return props.manualSorting ? (props.sorting ?? []) : internalSorting.value
}

const table = useVueTable({
  // TanStack uses `get` callbacks so it can react to prop changes.
  get data() { return props.data },
  get columns() { return props.columns },
  state: {
    get sorting() { return currentSorting() },
    get columnFilters() { return columnFilters.value },
  },
  onSortingChange: (updater) => {
    const next = typeof updater === 'function' ? updater(currentSorting()) : updater
    if (props.manualSorting) {
      emit('update:sorting', next)
    } else {
      internalSorting.value = next
    }
  },
  onColumnFiltersChange: (updater) => {
    columnFilters.value = typeof updater === 'function' ? updater(columnFilters.value) : updater
  },
  getCoreRowModel: getCoreRowModel(),
  // Skip sorted/filtered row models in manual mode — parent owns it.
  ...(props.manualSorting ? {} : { getSortedRowModel: getSortedRowModel() }),
  ...(props.manualFiltering ? {} : { getFilteredRowModel: getFilteredRowModel() }),
  manualSorting: props.manualSorting,
  manualFiltering: props.manualFiltering,
})

const rows = computed(() => table.getRowModel().rows)

function isFilterActive(columnId: string): boolean {
  if (props.manualFiltering) {
    return (props.activeFilterColumns ?? []).includes(columnId)
  }
  return columnFilters.value.some((f) => f.id === columnId)
}
</script>

<template>
  <div class="card overflow-visible relative">
    <div
      v-if="loading"
      class="absolute top-0 left-0 right-0 h-0.5 overflow-hidden pointer-events-none"
      aria-hidden="true"
    >
      <div class="datatable-loading-bar" />
    </div>
    <div v-if="rows.length === 0 && data.length === 0" class="p-8 text-center text-[var(--color-text-muted)] text-sm">
      {{ loading ? 'Loading…' : emptyText }}
    </div>
    <table v-else class="table">
      <thead>
        <tr v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
          <th
            v-for="header in headerGroup.headers"
            :key="header.id"
            :class="[
              header.column.columnDef.meta?.headerClass,
              header.column.columnDef.meta?.align === 'right' ? 'text-right' : '',
            ]"
            :style="header.column.getSize() !== 150 ? `width: ${header.column.getSize()}px` : ''"
          >
            <div
              class="inline-flex items-center gap-1"
              :class="header.column.columnDef.meta?.align === 'right' ? 'flex-row-reverse' : ''"
            >
              <span
                :class="header.column.getCanSort() ? 'cursor-pointer select-none inline-flex items-center gap-1' : 'inline-flex items-center gap-1'"
                @click="header.column.getCanSort() ? header.column.toggleSorting() : null"
              >
                <FlexRender :render="header.column.columnDef.header" :props="header.getContext()" />
                <template v-if="header.column.getCanSort()">
                  <ArrowUp v-if="header.column.getIsSorted() === 'asc'" :size="12" class="text-[var(--color-accent)]" />
                  <ArrowDown v-else-if="header.column.getIsSorted() === 'desc'" :size="12" class="text-[var(--color-accent)]" />
                  <ChevronsUpDown v-else :size="12" class="text-[var(--color-text-dim)] opacity-60" />
                </template>
              </span>

              <Popover
                v-if="showFilters && header.column.getCanFilter()"
                v-slot="{ open }"
                class="relative inline-flex"
              >
                <PopoverButton
                  type="button"
                  class="p-0.5 rounded transition-colors relative"
                  :class="[
                    isFilterActive(header.column.id) || open
                      ? 'text-[var(--color-accent)]'
                      : 'text-[var(--color-text-dim)] hover:text-[var(--color-text)]',
                  ]"
                  :aria-label="`Filter ${header.column.id}`"
                  @click.stop
                >
                  <Filter :size="12" />
                  <span
                    v-if="isFilterActive(header.column.id)"
                    class="absolute -top-0.5 -right-0.5 w-1.5 h-1.5 rounded-full"
                    style="background-color: var(--color-accent);"
                  />
                </PopoverButton>
                <Transition
                  enter-active-class="transition duration-100 ease-out"
                  enter-from-class="opacity-0 scale-95"
                  enter-to-class="opacity-100 scale-100"
                  leave-active-class="transition duration-75 ease-in"
                  leave-from-class="opacity-100 scale-100"
                  leave-to-class="opacity-0 scale-95"
                >
                  <PopoverPanel
                    class="absolute z-20 mt-1 w-64 rounded-lg shadow-lg p-3 normal-case tracking-normal"
                    :class="header.column.columnDef.meta?.align === 'right' ? 'right-0' : 'left-0'"
                    style="background-color: var(--color-surface); border: 1px solid var(--color-border);"
                  >
                    <slot
                      :name="`filter-${header.column.id}`"
                      :column="header.column"
                      :set-value="(v: unknown) => header.column.setFilterValue(v)"
                      :value="header.column.getFilterValue()"
                    >
                      <input
                        :value="(header.column.getFilterValue() as string | undefined) ?? ''"
                        class="input text-sm w-full"
                        placeholder="Filter…"
                        @input="header.column.setFilterValue(($event.target as HTMLInputElement).value)"
                      />
                    </slot>
                  </PopoverPanel>
                </Transition>
              </Popover>
            </div>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in rows"
          :key="row.id"
          :class="rowClickable ? 'cursor-pointer' : ''"
          @click="rowClickable && emit('row-click', row.original, $event)"
        >
          <td
            v-for="cell in row.getVisibleCells()"
            :key="cell.id"
            :class="[
              cell.column.columnDef.meta?.cellClass,
              cell.column.columnDef.meta?.align === 'right' ? 'text-right' : '',
            ]"
          >
            <slot
              :name="`cell-${cell.column.id}`"
              :row="row.original"
              :value="cell.getValue()"
            >
              <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
            </slot>
          </td>
        </tr>
        <tr v-if="rows.length === 0 && data.length > 0">
          <td :colspan="table.getAllLeafColumns().length" class="text-center text-[var(--color-text-muted)] !py-8">
            No matches.
          </td>
        </tr>
      </tbody>
    </table>

    <div
      v-if="showPagination"
      class="flex items-center justify-between gap-3 px-4 py-2.5 border-t text-xs"
      style="border-color: var(--color-border);"
    >
      <span class="text-[var(--color-text-muted)] tabular">
        <template v-if="total === 0">0 rows</template>
        <template v-else>{{ rangeStart }}–{{ rangeEnd }} of {{ total }}</template>
      </span>
      <div class="flex items-center gap-3">
        <label class="flex items-center gap-2 text-[var(--color-text-muted)]">
          <span>Rows</span>
          <select
            :value="pageSize"
            class="input !py-1 !px-2 text-xs"
            @change="emit('update:pageSize', Number(($event.target as HTMLSelectElement).value))"
          >
            <option v-for="opt in pageSizeOptions" :key="opt" :value="opt">{{ opt }}</option>
          </select>
        </label>
        <div class="flex items-center gap-1">
          <button
            type="button"
            class="p-1 rounded transition-colors disabled:opacity-40 disabled:cursor-not-allowed text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
            :disabled="(page ?? 1) <= 1 || loading"
            aria-label="Previous page"
            @click="goToPage((page ?? 1) - 1)"
          >
            <ChevronLeft :size="14" />
          </button>
          <span class="tabular text-[var(--color-text-muted)] px-2">
            Page <span class="text-[var(--color-text)]">{{ page }}</span> of {{ totalPages }}
          </span>
          <button
            type="button"
            class="p-1 rounded transition-colors disabled:opacity-40 disabled:cursor-not-allowed text-[var(--color-text-muted)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
            :disabled="(page ?? 1) >= totalPages || loading"
            aria-label="Next page"
            @click="goToPage((page ?? 1) + 1)"
          >
            <ChevronRight :size="14" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.datatable-loading-bar {
  width: 33%;
  height: 100%;
  background: var(--color-accent);
  animation: datatable-loading-slide 1.1s ease-in-out infinite;
}
@keyframes datatable-loading-slide {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(400%); }
}
</style>

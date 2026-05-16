<script setup lang="ts">
import { computed } from 'vue'
import { RANGES, type Range } from '@/lib/charts'
import SegmentedControl from '@/components/ui/SegmentedControl.vue'

defineProps<{ modelValue: Range }>()
defineEmits<{ 'update:modelValue': [Range] }>()

const options = computed(() => RANGES.map((r) => ({ value: r, label: r.toUpperCase() })))
</script>

<template>
  <SegmentedControl
    :model-value="modelValue"
    :options="options"
    @update:model-value="$emit('update:modelValue', $event as Range)"
  />
</template>

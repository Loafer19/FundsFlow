<template>
    <div class="flex flex-col items-center justify-center gap-3 py-24" role="status" aria-busy="true">
        <span class="loading loading-dots loading-md text-base-content/50" />
        <span class="text-sm text-base-content/60">Loading your data…</span>
    </div>
</template>

<script setup>
// Shown in place of the tab while the first data load runs (see isBootstrapping in services/bootstrap.js).
// The root must stay a single element so App's `invisible` class (first LOADER_DELAY_MS) lands on it.
</script>

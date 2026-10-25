<template>
    <div role="alert" class="alert alert-error alert-soft">
        <span>This view failed to load. Check your connection or reload the page.</span>
        <button type="button" class="btn btn-sm" @click="reload">Reload</button>
    </div>
</template>

<script setup>
const reload = () => window.location.reload()
</script>

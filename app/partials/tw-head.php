<?php declare(strict_types=1);
/**
 * Tailwind CSS CDN + config inline.
 * Include once inside <head> of each layout template.
 */
?>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        accent: { DEFAULT: '#18181b', light: 'rgba(24,24,27,.06)', mid: 'rgba(24,24,27,.18)' },
        gold: '#27272a',
        ink: '#18181b',
        muted: '#71717a',
        line: '#e4e4e7',
        surface: '#fafafa',
        card: '#ffffff',
        input: '#fafafa',
        hover: '#f4f4f5',
        ok: { DEFAULT: '#15803d', bg: '#f0fdf4' },
        warn: { DEFAULT: '#b45309', bg: '#fffbeb' },
        danger: { DEFAULT: '#b91c1c', bg: '#fef2f2' },
        offline: { bg: '#f4f4f5' },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif']
      },
      borderRadius: {
        card: '6px',
        btn: '6px',
        pill: '999px'
      }
    }
  }
}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  html { color-scheme: light; }
</style>

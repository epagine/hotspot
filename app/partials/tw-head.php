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
        accent: { DEFAULT: '#c8892a', light: 'rgba(200,137,42,.10)', mid: 'rgba(200,137,42,.45)' },
        gold: '#e8b058',
        ink: '#15202b',
        muted: '#667788',
        line: '#dde3ea',
        surface: '#f3f5f8',
        card: '#ffffff',
        input: '#f8fafc',
        hover: '#eef2f6',
        ok: { DEFAULT: '#1f7a3a', bg: '#e8f6ea' },
        warn: { DEFAULT: '#9a6b12', bg: '#fff4df' },
        danger: { DEFAULT: '#b42318', bg: '#fdeceb' },
        offline: { bg: '#eef1f4' },
        dark: { bg: '#120f0c', card: '#1c1814', ink: '#faf4e8', muted: '#c4b8a4', line: '#3a3228', gold: '#e8b058' }
      },
      fontFamily: {
        sans: ['Figtree', 'Inter', 'system-ui', 'sans-serif']
      },
      borderRadius: {
        card: '16px',
        btn: '10px',
        pill: '999px'
      }
    }
  }
}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">

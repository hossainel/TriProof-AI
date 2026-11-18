@props(['name', 'class' => 'h-5 w-5'])

<svg {{ $attributes->merge([
    'class' => $class,
    'viewBox' => '0 0 24 24',
    'fill' => 'none',
    'stroke' => 'currentColor',
    'stroke-width' => '1.8',
    'stroke-linecap' => 'round',
    'stroke-linejoin' => 'round',
    'aria-hidden' => 'true',
]) }}>
    @switch($name)
        @case('dashboard')
            <rect x="3" y="3" width="7" height="9" rx="1.5" />
            <rect x="14" y="3" width="7" height="5" rx="1.5" />
            <rect x="14" y="12" width="7" height="9" rx="1.5" />
            <rect x="3" y="16" width="7" height="5" rx="1.5" />
            @break

        @case('verify')
        @case('shield')
            <path d="M12 3 4.5 6v5.2c0 4.8 3.1 8.2 7.5 9.8 4.4-1.6 7.5-5 7.5-9.8V6L12 3Z" />
            <path d="m8.7 12.1 2.2 2.2 4.4-4.5" />
            @break

        @case('orders')
            <path d="M9 5H6.8A1.8 1.8 0 0 0 5 6.8v12.4A1.8 1.8 0 0 0 6.8 21h10.4a1.8 1.8 0 0 0 1.8-1.8V6.8A1.8 1.8 0 0 0 17.2 5H15" />
            <rect x="9" y="3" width="6" height="4" rx="1.2" />
            <path d="M8.5 12h7M8.5 16h4.5" />
            @break

        @case('batches')
        @case('layers')
            <path d="m12 3 8.5 4.5L12 12 3.5 7.5 12 3Z" />
            <path d="m4 12 8 4.2 8-4.2" />
            <path d="m4 16.5 8 4.2 8-4.2" />
            @break

        @case('anomalies')
        @case('activity')
            <path d="M3 12h4l2.2-6 4 12 2.4-6H21" />
            <circle cx="18.5" cy="5" r="1.5" />
            @break

        @case('recall')
        @case('alert')
            <path d="M12 4.2 2.9 19.2h18.2L12 4.2Z" />
            <path d="M12 9v4.5" />
            <circle cx="12" cy="16.7" r=".7" fill="currentColor" stroke="none" />
            @break

        @case('rework')
            <path d="M7 4v10.5a3.5 3.5 0 0 0 3.5 3.5H17" />
            <path d="m14 15 3 3-3 3" />
            <path d="M7 4 4 7l3 3" />
            @break

        @case('key')
            <circle cx="8" cy="15" r="4" />
            <path d="m11 12 8.5-8.5M16 7l3 3M14 9l2 2" />
            @break

        @case('health')
            <path d="M3 12h4l2-5 3.5 10 2.4-6.5L17 12h4" />
            @break

        @case('logout')
            <path d="M10 5H6.8A1.8 1.8 0 0 0 5 6.8v10.4A1.8 1.8 0 0 0 6.8 19H10" />
            <path d="M14 8l4 4-4 4M18 12H9" />
            @break

        @case('menu')
            <path d="M4 7h16M4 12h16M4 17h16" />
            @break

        @case('close')
        @case('x')
            <path d="m6 6 12 12M18 6 6 18" />
            @break

        @case('plus')
            <path d="M12 5v14M5 12h14" />
            @break

        @case('download')
            <path d="M12 4v11M7.5 10.5 12 15l4.5-4.5" />
            <path d="M5 19h14" />
            @break

        @case('refresh')
            <path d="M20 7v5h-5" />
            <path d="M4 17v-5h5" />
            <path d="M6.1 8.2A7 7 0 0 1 19.4 12M4.6 12a7 7 0 0 0 13.3 3.8" />
            @break

        @case('upload')
            <path d="M12 16V5M7.5 9.5 12 5l4.5 4.5" />
            <path d="M5 19h14" />
            @break

        @case('sparkles')
            <path d="m12 3 1.2 4.2L17 9l-3.8 1.8L12 15l-1.2-4.2L7 9l3.8-1.8L12 3Z" />
            <path d="m19 14 .7 2.3L22 17l-2.3.7L19 20l-.7-2.3L16 17l2.3-.7L19 14Z" />
            <path d="m5 12 .6 2L7.5 15l-1.9.9L5 18l-.6-2.1L2.5 15l1.9-1L5 12Z" />
            @break

        @case('chart')
            <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />
            @break

        @case('map')
            <path d="m9 4-5 2v14l5-2 6 2 5-2V4l-5 2-6-2Z" />
            <path d="M9 4v14M15 6v14" />
            @break

        @case('check')
            <path d="m5 12.5 4.5 4.5L19 6.5" />
            @break

        @case('check-circle')
            <circle cx="12" cy="12" r="9" />
            <path d="m8 12 2.7 2.7 5.5-5.5" />
            @break

        @case('clock')
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
            @break

        @case('user')
            <circle cx="12" cy="8" r="3.5" />
            <path d="M5 20a7 7 0 0 1 14 0" />
            @break

        @case('users')
            <circle cx="9" cy="8" r="3" />
            <path d="M3.5 19a5.5 5.5 0 0 1 11 0" />
            <path d="M16 5.5a3 3 0 0 1 0 5.8M17.5 14a5.5 5.5 0 0 1 3 5" />
            @break

        @case('factory')
            <path d="M3 21V9l6 3V9l6 3V4h3l1 17H3Z" />
            <path d="M7 17h2M13 17h2" />
            @break

        @case('search')
            <circle cx="10.5" cy="10.5" r="6.5" />
            <path d="m16 16 4.5 4.5" />
            @break

        @case('copy')
            <rect x="8" y="8" width="11" height="11" rx="2" />
            <path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" />
            @break

        @case('external')
            <path d="M14 4h6v6M20 4l-9 9" />
            <path d="M18 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5" />
            @break

        @case('arrow-right')
            <path d="M5 12h14M14 7l5 5-5 5" />
            @break

        @case('database')
            <ellipse cx="12" cy="5.5" rx="8" ry="3" />
            <path d="M4 5.5v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" />
            <path d="M4 11.5v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" />
            @break

        @case('lock')
            <rect x="5" y="10" width="14" height="10" rx="2" />
            <path d="M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10" />
            @break

        @case('file')
            <path d="M7 3h7l4 4v14H7V3Z" />
            <path d="M14 3v5h5M10 13h5M10 17h5" />
            @break

        @case('code')
            <path d="m8 8-4 4 4 4M16 8l4 4-4 4M13 5l-2 14" />
            @break

        @case('eye')
            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
            <circle cx="12" cy="12" r="2.5" />
            @break

        @case('info')
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5M12 8h.01" />
            @break

        @case('chevron-down')
            <path d="m6 9 6 6 6-6" />
            @break

        @case('trending')
            <path d="m3 17 6-6 4 4 7-7" />
            <path d="M15 8h5v5" />
            @break

        @default
            <circle cx="12" cy="12" r="8" />
    @endswitch
</svg>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', $pageTitle ?? 'GUJAJOB WebApp')</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Thai:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* ── Sidebar submenu (อนุมัติการเบิกวัสดุ) ───────────────────────── */
        .menu-sublist { margin: 0; }
        .menu-subitem {
            position: relative;
            display: flex;
            align-items: center;
            min-height: 32px;
            padding: 5px 16px 5px 44px;
            color: #7f8da5;
            text-decoration: none;
            font-size: 12px;
            font-weight: 400;
            white-space: nowrap;
            transition: background 0.15s, color 0.15s;
        }
        .menu-subitem::before {
            content: '';
            position: absolute;
            left: 30px;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: currentColor;
            opacity: 0.5;
        }
        .menu-subitem:hover {
            background: rgba(255,255,255,0.05);
            color: #d6deec;
        }
        .menu-subitem.active {
            color: #93c5fd;
            font-weight: 600;
            background: rgba(37,72,189,0.2);
        }
        .menu-subitem.active::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 3px;
            height: 100%;
            background: #4b7cff;
            border-radius: 0 2px 2px 0;
        }
        /* Parent menu item when a child submenu is active */
        .menu-item.menu-item-parent-active {
            background: rgba(37,72,189,0.15);
            color: #d6deec;
            font-weight: 500;
        }
        .menu-item.menu-item-parent-active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background: rgba(75,124,255,0.45);
        }

        /* ── Page header row (title + user badge on same row) ───────── */
        .page-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px 16px;
            margin-bottom: 16px;
        }
        .page-header-row > h2 {
            margin: 0 !important;
            flex: 1 1 auto;
            min-width: 0;
        }
        .page-header-row > .content-topbar-user {
            flex-shrink: 0;
        }

        /* ── Content top bar ──────────────────────────────────────────────── */
        .content-topbar {
            display: none; /* replaced by x-page-header component */
        }

        /* ── User badge (display-only, no dropdown) ───────────────────── */
        .content-topbar-user {
            position: relative;
        }

        .user-badge {
            display: inline-flex;
            align-items: center;
            background: #dbeafe;
            color: #1d4ed8;
            border: 1.5px solid #bfdbfe;
            border-radius: 8px;
            padding: 5px 12px;
            font-size: 13.5px;
            font-weight: 600;
            white-space: nowrap;
            line-height: 1.4;
            cursor: default;
        }

        /* ── Sidebar logout button ───────────────────────────────────────── */
        .sidebar-logout {
            padding: 10px 16px 12px;
            border-bottom: 1px solid #243044;
        }

        .sidebar-logout form { margin: 0; }

        .logout-btn {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 6px;
            color: rgba(214,222,236,0.85);
            font-family: inherit;
            font-size: 12px;
            font-weight: 500;
            padding: 5px 12px;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }

        .logout-btn:hover {
            background: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.3);
            color: #ffffff;
        }
    </style>
    @yield('page-style')
    @vite(['resources/css/components/form-buttons.css'])
    @vite(['resources/css/components/page-layout.css'])
    @vite(['resources/css/components/confirm-icon.css'])
</head>
<body>
    <svg class="svg-sprite" xmlns="http://www.w3.org/2000/svg">
        <symbol id="icon-box" viewBox="0 0 24 24">
            <path d="M21 16V8a2 2 0 0 0-1-1.73L13 2.27a2 2 0 0 0-2 0L4 6.27A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/>
            <path d="M3.3 7 12 12l8.7-5"/>
            <path d="M12 22V12"/>
        </symbol>

        <symbol id="icon-chevron-down" viewBox="0 0 24 24">
            <path d="m6 9 6 6 6-6"/>
        </symbol>

        <symbol id="icon-menu" viewBox="0 0 24 24">
            <path d="M4 7h16"/>
            <path d="M4 12h16"/>
            <path d="M4 17h16"/>
        </symbol>

        <symbol id="icon-download" viewBox="0 0 24 24">
            <path d="M12 3v12"/>
            <path d="m7 10 5 5 5-5"/>
            <path d="M5 21h14"/>
        </symbol>

        <symbol id="icon-upload" viewBox="0 0 24 24">
            <path d="M12 21V9"/>
            <path d="m7 14 5-5 5 5"/>
            <path d="M5 3h14"/>
        </symbol>

        <symbol id="icon-transfer" viewBox="0 0 24 24">
            <path d="M7 7h13"/>
            <path d="m17 4 3 3-3 3"/>
            <path d="M17 17H4"/>
            <path d="m7 14-3 3 3 3"/>
        </symbol>

        <symbol id="icon-clipboard" viewBox="0 0 24 24">
            <path d="M9 4h6"/>
            <path d="M9 2h6v4H9z"/>
            <path d="M5 5h14v17H5z"/>
            <path d="M8 10h8"/>
            <path d="M8 14h8"/>
            <path d="M8 18h5"/>
        </symbol>

        <symbol id="icon-archive" viewBox="0 0 24 24">
            <path d="M4 7h16"/>
            <path d="M5 7v13h14V7"/>
            <path d="M8 3h8l2 4H6z"/>
            <path d="M10 12h4"/>
        </symbol>

        <symbol id="icon-printer" viewBox="0 0 24 24">
            <path d="M7 9V3h10v6"/>
            <path d="M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/>
            <path d="M7 14h10v7H7z"/>
            <path d="M17 12h.01"/>
        </symbol>

        <symbol id="icon-grid" viewBox="0 0 24 24">
            <path d="M4 4h7v7H4z"/>
            <path d="M13 4h7v7h-7z"/>
            <path d="M4 13h7v7H4z"/>
            <path d="M13 13h7v7h-7z"/>
        </symbol>

        <symbol id="icon-building" viewBox="0 0 24 24">
            <path d="M4 21V4h16v17"/>
            <path d="M8 8h2"/>
            <path d="M14 8h2"/>
            <path d="M8 12h2"/>
            <path d="M14 12h2"/>
            <path d="M8 16h2"/>
            <path d="M14 16h2"/>
        </symbol>

        <symbol id="icon-file" viewBox="0 0 24 24">
            <path d="M6 2h9l5 5v15H6z"/>
            <path d="M14 2v6h6"/>
            <path d="M9 13h6"/>
            <path d="M9 17h6"/>
        </symbol>

        <symbol id="icon-users" viewBox="0 0 24 24">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
            <path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/>
            <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </symbol>

        <symbol id="icon-bell" viewBox="0 0 24 24">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </symbol>

        <symbol id="icon-check-circle" viewBox="0 0 24 24">
            <path d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            <path d="m9 12 2 2 4-4"/>
        </symbol>

        <symbol id="icon-check" viewBox="0 0 24 24">
            <path d="M20 6 9 17l-5-5"/>
        </symbol>

        <symbol id="icon-list-check" viewBox="0 0 24 24">
            <path d="M4 6h11"/>
            <path d="M4 12h9"/>
            <path d="M4 18h7"/>
            <path d="m15 17 2 2 4-4"/>
        </symbol>

        <symbol id="icon-document" viewBox="0 0 24 24">
            <path d="M6 2h9l5 5v15H6z"/>
            <path d="M14 2v6h6"/>
        </symbol>

        <symbol id="icon-panel" viewBox="0 0 24 24">
            <rect x="4" y="4" width="16" height="16" rx="3"/>
            <path d="M10 4v16"/>
        </symbol>

        <symbol id="icon-material-list" viewBox="0 0 24 24">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
            <path d="M9 3v18"/>
            <path d="M13 8h5"/>
            <path d="M13 12h5"/>
            <path d="M13 16h4"/>
        </symbol>

        <symbol id="icon-edit-form" viewBox="0 0 24 24">
            <path d="M12 20h9"/>
            <path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L8 18l-4 1 1-4Z"/>
            <path d="m15 5 3 3"/>
            <path d="M4 22h16"/>
        </symbol>

        <symbol id="icon-success" viewBox="0 0 24 24">
            <path d="M20 6 9 17l-5-5"/>
        </symbol>

        <symbol id="icon-edit-confirm" viewBox="0 0 24 24">
            <path d="M12 20h9"/>
            <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/>
            <path d="m15 5 3 3"/>
            <path d="M4 22h16"/>
        </symbol>


        <symbol id="icon-square-pen" viewBox="0 0 24 24">
            <path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.375 2.625a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4Z"/>
        </symbol>

        <symbol id="icon-report-file" viewBox="0 0 24 24">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/>
            <path d="M14 2v6h6"/>
            <path d="M9 15h6"/>
            <path d="M9 18h6"/>
            <path d="M9 12h2"/>
        </symbol>

        <symbol id="icon-report-chart" viewBox="0 0 24 24">
            <path d="M3 3v18h18"/>
            <path d="M8 17V9"/>
            <path d="M13 17V5"/>
            <path d="M18 17v-4"/>
        </symbol>

        <symbol id="icon-report-receipt" viewBox="0 0 24 24">
            <path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2Z"/>
            <path d="M8 7h8"/>
            <path d="M8 11h8"/>
            <path d="M8 15h5"/>
        </symbol>

        <symbol id="icon-filter-report" viewBox="0 0 24 24">
            <path d="M3 5h18"/>
            <path d="M6 12h12"/>
            <path d="M10 19h4"/>
        </symbol>

        <symbol id="icon-download-report" viewBox="0 0 24 24">
            <path d="M12 3v12"/>
            <path d="m7 10 5 5 5-5"/>
            <path d="M5 21h14"/>
        </symbol>

        <symbol id="icon-file-text" viewBox="0 0 24 24">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <path d="M14 2v6h6"/>
            <path d="M16 13H8"/>
            <path d="M16 17H8"/>
            <path d="M10 9H8"/>
        </symbol>

        <symbol id="icon-alert-triangle" viewBox="0 0 24 24">
            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
            <path d="M12 9v4"/>
            <path d="M12 17h.01"/>
        </symbol>

        <symbol id="icon-search-filter" viewBox="0 0 24 24">
            <path d="M3 5h18"/>
            <path d="M6 12h12"/>
            <path d="M10 19h4"/>
        </symbol>

        <symbol id="icon-trash" viewBox="0 0 24 24">
            <path d="M3 6h18"/>
            <path d="M8 6V4h8v2"/>
            <path d="M19 6l-1 14H6L5 6"/>
            <path d="M10 11v6"/>
            <path d="M14 11v6"/>
        </symbol>

    </svg>

    <div class="app-shell">
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-icon">
                    <svg><use href="#icon-box"></use></svg>
                </div>

                <div class="brand-text">
                    <h1>ระบบบริหารจัดการ</h1>
                    <p>คลังวัสดุและครุภัณฑ์</p>
                </div>
            </div>

            <div class="sidebar-logout">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="logout-btn">ออกจากระบบ</button>
                </form>
            </div>

            <nav class="menu">
                <section class="menu-group" data-sidebar-group="material">
                    <button class="menu-group-toggle" type="button" aria-expanded="true">
                        <span>การจัดการวัสดุ</span>
                        <svg class="group-chevron"><use href="#icon-chevron-down"></use></svg>
                    </button>

                    <div class="menu-group-panel">
                        <a class="menu-item {{ request()->routeIs('material.items.*') ? 'active' : '' }}" href="{{ route('material.items.index') }}">
                            <svg class="menu-icon"><use href="#icon-menu"></use></svg>
                            <span>จัดการรายการวัสดุ</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('material.receiving.*') ? 'active' : '' }}" href="{{ route('material.receiving.index') }}">
                            <svg class="menu-icon"><use href="#icon-download"></use></svg>
                            <span>บันทึกการรับวัสดุเข้าคลัง</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('material.withdraw.*') && !request()->routeIs('material.withdraw.approval.*') ? 'active' : (request()->routeIs('material.withdraw.approval.*') ? 'menu-item-parent-active' : '') }}" href="{{ route('material.withdraw.index') }}">
                            <svg class="menu-icon"><use href="#icon-upload"></use></svg>
                            <span>เบิกวัสดุ</span>
                        </a>

                        <div class="menu-sublist">
                            <a class="menu-subitem {{ request()->routeIs('material.withdraw.approval.*') ? 'active' : '' }}" href="{{ route('material.withdraw.approval.index') }}">อนุมัติการเบิกวัสดุ</a>
                        </div>

                        <a class="menu-item {{ request()->routeIs('material.transfer.*') ? 'active' : '' }}" href="{{ route('material.transfer.index') }}">
                            <svg class="menu-icon"><use href="#icon-transfer"></use></svg>
                            <span>รับโอนวัสดุ</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('material.register.*') ? 'active' : '' }}" href="{{ route('material.register.index') }}">
                            <svg class="menu-icon"><use href="#icon-clipboard"></use></svg>
                            <span>คุมทะเบียนวัสดุ</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('material.balance.*') ? 'active' : '' }}" href="{{ route('material.balance.index') }}">
                            <svg class="menu-icon"><use href="#icon-archive"></use></svg>
                            <span>บันทึกการตั้งยอดคงเหลือ</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('material.report.*') ? 'active' : '' }}" href="{{ route('material.report.index') }}">
                            <svg class="menu-icon"><use href="#icon-printer"></use></svg>
                            <span>จัดพิมพ์รายงาน</span>
                        </a>
                    </div>
                </section>

                <section class="menu-group" data-sidebar-group="asset">
                    <button class="menu-group-toggle" type="button" aria-expanded="true">
                        <span>การจัดการครุภัณฑ์</span>
                        <svg class="group-chevron"><use href="#icon-chevron-down"></use></svg>
                    </button>

                    <div class="menu-group-panel">
                        <a class="menu-item {{ request()->routeIs('asset.categories.*') ? 'active' : '' }}" href="{{ route('asset.categories.index') }}">
                            <svg class="menu-icon"><use href="#icon-grid"></use></svg>
                            <span>จัดการประเภทครุภัณฑ์</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('asset.suppliers.*') ? 'active' : '' }}" href="{{ route('asset.suppliers.index') }}">
                            <svg class="menu-icon"><use href="#icon-building"></use></svg>
                            <span>จัดการข้อมูลผู้ประกอบการ</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('asset.registrations.*') ? 'active' : '' }}" href="{{ route('asset.registrations.index') }}">
                            <svg class="menu-icon"><use href="#icon-file-text"></use></svg>
                            <span>จัดการทะเบียนครุภัณฑ์</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('asset.assignments.*') ? 'active' : '' }}" href="{{ route('asset.assignments.index') }}">
                            <svg class="menu-icon"><use href="#icon-upload"></use></svg>
                            <span>จัดสรรครุภัณฑ์ให้หน่วยงาน</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('asset.department-receiving.*') ? 'active' : '' }}" href="{{ route('asset.department-receiving.index') }}">
                            <svg class="menu-icon"><use href="#icon-users"></use></svg>
                            <span>รับครุภัณฑ์ลงทะเบียนหน่วยงาน</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('asset.disposals.index', 'asset.disposals.create', 'asset.disposals.show', 'asset.disposals.edit') ? 'active' : '' }}" href="{{ route('asset.disposals.index') }}">
                            <svg class="menu-icon"><use href="#icon-bell"></use></svg>
                            <span>แจ้งขอจำหน่ายครุภัณฑ์</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('asset.disposals.approval.*') ? 'active' : '' }}" href="{{ route('asset.disposals.approval.index') }}">
                            <svg class="menu-icon"><use href="#icon-check-circle"></use></svg>
                            <span>อนุมัติแจ้งจำหน่ายครุภัณฑ์</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('asset.disposals.results.*') ? 'active' : '' }}" href="{{ route('asset.disposals.results.index') }}">
                            <svg class="menu-icon"><use href="#icon-list-check"></use></svg>
                            <span>บันทึกผลการจำหน่ายครุภัณฑ์</span>
                        </a>

                        <a class="menu-item {{ request()->routeIs('asset.reports.*') ? 'active' : '' }}" href="{{ route('asset.reports.index') }}">
                            <svg class="menu-icon"><use href="#icon-printer"></use></svg>
                            <span>จัดพิมพ์รายงาน</span>
                        </a>
                    </div>
                </section>
            </nav>
        </aside>

        <main class="content">
            <div class="content-topbar">
                <div class="content-topbar-user">
                    <span class="user-badge">{{ auth()->user()->user_name ?? '' }}</span>
                </div>
            </div>

            @yield('content')
        </main>
    </div>

    @yield('page-script')
</body>
</html>

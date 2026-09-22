<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jinx WIP</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        .wip-page {
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        }
        .wip-header-block {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-bottom: 18px;
        }
        .wip-header-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .wip-header-title {
            font-size: 1.375rem;
            font-weight: 600;
            letter-spacing: -0.03em;
            color: #f8fafc;
        }
        .wip-header-sub {
            font-size: 12px;
            color: #64748b;
            margin-top: 3px;
        }
        .wip-refresh-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid rgba(71, 85, 105, 0.75);
            background: rgba(30, 41, 59, 0.45);
            color: #cbd5e1;
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .wip-refresh-btn:hover {
            background: rgba(51, 65, 85, 0.55);
            border-color: #64748b;
            color: #f1f5f9;
        }
        .wip-header-scope-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }
        .wip-attention-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            background: rgba(127, 29, 29, 0.5);
            border: 1px solid rgba(248, 113, 113, 0.5);
            color: #fee2e2;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .wip-scope-segment {
            display: inline-flex;
            align-items: stretch;
            border-radius: 8px;
            border: 1px solid rgba(71, 85, 105, 0.65);
            background: rgba(15, 23, 42, 0.55);
            overflow: hidden;
        }
        .wip-scope-segment__link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            color: #94a3b8;
            border-right: 1px solid rgba(71, 85, 105, 0.45);
            transition: background 0.15s ease, color 0.15s ease;
            white-space: nowrap;
        }
        .wip-scope-segment__link:last-child {
            border-right: none;
        }
        .wip-scope-segment__link:hover {
            color: #e2e8f0;
            background: rgba(51, 65, 85, 0.25);
        }
        .wip-scope-segment__link--active {
            color: #f1f5f9;
            background: rgba(59, 130, 246, 0.18);
            font-weight: 600;
        }
        .wip-filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 14px;
        }
        .wip-filter-bar input[type="search"],
        .wip-filter-bar select {
            box-sizing: border-box;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid rgba(71, 85, 105, 0.75);
            background: rgba(15, 23, 42, 0.65);
            color: #f9fafb;
            font-size: 13px;
        }
        .wip-filter-bar input[type="search"]::placeholder {
            color: #64748b;
        }
        .wip-filter-bar input[type="search"] {
            flex: 1;
            min-width: 180px;
            max-width: 320px;
        }
        .wip-filter-bar select {
            min-width: 180px;
        }
        .wip-card {
            background: linear-gradient(165deg, rgba(17, 24, 39, 0.98) 0%, rgba(15, 23, 42, 0.99) 100%);
            border: 1px solid rgba(51, 65, 85, 0.55);
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .wip-card__row1 {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .wip-card__title {
            margin: 0;
            min-width: 0;
            flex: 1;
        }
        .wip-card__title a {
            color: #f8fafc;
            text-decoration: none;
            font-size: 1.0625rem;
            font-weight: 600;
            line-height: 1.3;
            letter-spacing: -0.02em;
            display: inline-block;
            word-break: break-word;
        }
        .wip-card__title a:hover {
            color: #cbd5e1;
        }
        .wip-card-actions {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            flex-shrink: 0;
            max-width: min(100%, 20rem);
        }
        .wip-chip {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 500;
            line-height: 1.25;
            letter-spacing: 0.02em;
            white-space: nowrap;
            border: 1px solid transparent;
        }
        .wip-card-actions .jinx-ctc-btn.jinx-ctc-btn--icon {
            margin: 0;
            width: 38px;
            height: 38px;
            min-width: 38px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0;
            line-height: 0;
            color: #93c5fd;
            background: rgba(37, 99, 235, 0.18);
            border: 1px solid rgba(59, 130, 246, 0.42);
            border-radius: 8px;
            cursor: pointer;
            box-shadow: none;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .wip-card-actions .jinx-ctc-btn.jinx-ctc-btn--icon svg {
            width: 20px;
            height: 20px;
        }
        .wip-card-actions .jinx-ctc-btn.jinx-ctc-btn--icon:hover {
            background: rgba(37, 99, 235, 0.32);
            border-color: rgba(96, 165, 250, 0.55);
            color: #e0f2fe;
        }
        .wip-card-actions .jinx-ctc-btn.jinx-ctc-btn--icon:focus-visible {
            outline: 2px solid rgba(96, 165, 250, 0.75);
            outline-offset: 2px;
        }
        .wip-card-actions .jinx-ctc-btn.jinx-ctc-btn--icon:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }
        .wip-card__meta {
            font-size: 11px;
            line-height: 1.5;
            color: #64748b;
            padding-top: 4px;
            border-top: 1px solid rgba(51, 65, 85, 0.35);
        }
        .wip-meta-k { color: #64748b; font-weight: 500; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em; }
        .wip-meta-v { color: #94a3b8; font-weight: 400; font-size: 12px; }
        .wip-meta-dot { color: #475569; margin: 0 0.25em; user-select: none; }
        .wip-meta-pill {
            display: inline-block;
            vertical-align: middle;
            margin: 1px 0 1px 0.35em;
            padding: 2px 6px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 500;
            background: rgba(30, 41, 59, 0.65);
            border: 1px solid rgba(51, 65, 85, 0.6);
            color: #94a3b8;
        }
        .wip-card__controls {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .wip-card__controls .status-select {
            flex: 1;
            min-width: 180px;
            min-height: 36px;
            background: rgba(15, 23, 42, 0.9);
            color: #f1f5f9;
            border: 1px solid rgba(71, 85, 105, 0.65);
            border-radius: 7px;
            padding: 7px 10px;
            font-size: 13px;
            font-weight: 450;
        }
        .wip-attention-title {
            margin: 0;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #fef2f2;
        }
        .wip-attention-section {
            margin-bottom: 16px;
            border-radius: 12px;
            border: 1px solid rgba(248, 113, 113, 0.42);
            background: linear-gradient(180deg, rgba(69, 10, 10, 0.3) 0%, rgba(15, 23, 42, 0.65) 100%);
            padding: 12px;
        }
        .wip-attention-section--active {
            animation: wip-attention-pulse 3.2s ease-in-out infinite;
        }
        @keyframes wip-attention-pulse {
            0%, 100% { box-shadow: 0 0 0 1px rgba(251, 113, 133, 0.14), 0 0 0 rgba(0, 0, 0, 0); }
            50% { box-shadow: 0 0 0 1px rgba(251, 146, 60, 0.28), 0 8px 24px rgba(248, 113, 113, 0.08); }
        }
        .wip-attention-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .wip-attention-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            padding: 4px 9px;
            border-radius: 999px;
            border: 1px solid rgba(251, 146, 60, 0.5);
            background: rgba(154, 52, 18, 0.45);
            color: #ffedd5;
            font-size: 11px;
            font-weight: 700;
        }
        .wip-attention-grid {
            display: grid;
            gap: 10px;
        }
        .wip-card-attention {
            border-color: rgba(248, 113, 113, 0.45);
            background: linear-gradient(165deg, rgba(45, 19, 19, 0.6) 0%, rgba(15, 23, 42, 0.99) 55%);
        }
        .wip-channel-chip {
            background: rgba(127, 29, 29, 0.5);
            border-color: rgba(252, 165, 165, 0.4);
            color: #fee2e2;
        }
        .wip-attention-empty {
            border: 1px dashed rgba(248, 113, 113, 0.35);
            border-radius: 10px;
            padding: 10px 12px;
            color: #fecaca;
            font-size: 12px;
            background: rgba(30, 41, 59, 0.35);
        }
        .wip-action-form {
            display: inline;
            margin: 0;
        }
        .wip-attention-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 30px;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid rgba(71, 85, 105, 0.55);
            background: rgba(30, 41, 59, 0.55);
            color: #e2e8f0;
            font-size: 11px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }
        .wip-attention-btn:hover {
            background: rgba(51, 65, 85, 0.55);
            border-color: rgba(100, 116, 139, 0.7);
        }
        .wip-attention-btn--dead {
            background: rgba(127, 29, 29, 0.55);
            border-color: rgba(239, 68, 68, 0.45);
            color: #fee2e2;
        }
        .wip-attention-btn--ignore {
            background: rgba(51, 65, 85, 0.4);
            color: #cbd5e1;
        }
        .wip-flash {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            border: 1px solid rgba(16, 185, 129, 0.45);
            background: rgba(6, 78, 59, 0.35);
            color: #d1fae5;
        }
        .wip-chip-outstanding {
            font: inherit;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, transform 0.08s ease;
        }
        .wip-chip-outstanding:hover {
            filter: brightness(1.08);
        }
        .wip-chip-outstanding:active {
            transform: scale(0.98);
        }
        .wip-chip-outstanding:focus-visible {
            outline: 2px solid rgba(96, 165, 250, 0.65);
            outline-offset: 1px;
        }
        .wip-chip-outstanding--clear {
            background: rgba(6, 78, 59, 0.45);
            border-color: rgba(16, 185, 129, 0.45);
            color: #d1fae5;
        }
        .wip-chip-outstanding--pending {
            background: rgba(120, 53, 15, 0.45);
            border-color: rgba(245, 158, 11, 0.45);
            color: #fef3c7;
        }
        .wip-card-actions .open-checklist-btn.wip-chip-outstanding {
            min-height: unset;
            min-width: unset;
            padding: 3px 8px;
            font-size: 10px;
            font-weight: 500;
            border-radius: 999px;
            line-height: 1.25;
            box-shadow: none;
        }
        @keyframes wip-priority-glow {
            0%, 100% { box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.15); }
            50% { box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.28), 0 4px 20px rgba(37, 99, 235, 0.06); }
        }
        .wip-card-priority {
            animation: wip-priority-glow 5s ease-in-out infinite;
            border-color: rgba(59, 130, 246, 0.35) !important;
        }
        @keyframes wip-undialled-pulse {
            0%, 100% { box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.28), 0 2px 16px rgba(245, 158, 11, 0.06); }
            50% { box-shadow: 0 0 0 1px rgba(251, 191, 36, 0.4), 0 4px 22px rgba(245, 158, 11, 0.1); }
        }
        .wip-card-undialled-attention {
            animation: wip-undialled-pulse 2.8s ease-in-out infinite;
            border-color: rgba(245, 158, 11, 0.45) !important;
            background: linear-gradient(165deg, rgba(28, 20, 18, 0.55) 0%, rgba(15, 23, 42, 0.98) 55%) !important;
        }
        .wip-card__badge--undialled {
            background: rgba(154, 52, 18, 0.55);
            border-color: rgba(251, 191, 36, 0.35);
            color: #fffbeb;
            font-weight: 500;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-size: 9px;
        }
        @keyframes wip-reengagement-pulse {
            0%, 100% {
                box-shadow: 0 0 0 1px rgba(244, 63, 94, 0.35), 0 4px 24px rgba(244, 63, 94, 0.08);
            }
            50% {
                box-shadow: 0 0 0 1px rgba(251, 113, 133, 0.45), 0 6px 28px rgba(244, 63, 94, 0.12);
            }
        }
        .wip-card-reengagement-unseen {
            animation: wip-reengagement-pulse 3s ease-in-out infinite;
            border-color: rgba(244, 63, 94, 0.42) !important;
            background: linear-gradient(165deg, rgba(30, 20, 24, 0.65) 0%, rgba(17, 24, 39, 0.98) 50%) !important;
        }
        .wip-card-reengagement-seen {
            border-left: 3px solid rgba(244, 63, 94, 0.35);
            background: linear-gradient(90deg, rgba(30, 20, 24, 0.35) 0%, rgba(15, 23, 42, 0.4) 8%, transparent 28%),
                linear-gradient(165deg, rgba(17, 24, 39, 0.98) 0%, rgba(15, 23, 42, 0.99) 100%) !important;
        }
        .wip-card__badge--reengaged {
            background: linear-gradient(135deg, rgba(185, 28, 28, 0.88) 0%, rgba(194, 65, 12, 0.82) 100%);
            border: 1px solid rgba(254, 202, 202, 0.35);
            color: #fff7ed;
            font-weight: 600;
            letter-spacing: 0.01em;
            font-size: 10px;
            box-shadow: 0 1px 8px rgba(0, 0, 0, 0.2);
        }
        .wip-card-reengagement-seen .wip-card__badge--reengaged {
            background: linear-gradient(135deg, rgba(127, 29, 29, 0.55) 0%, rgba(124, 45, 18, 0.5) 100%);
            border-color: rgba(252, 165, 165, 0.2);
            font-weight: 500;
        }
        .wip-card__badge--reengagement-channel {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(249, 115, 22, 0.4);
            color: #fed7aa;
            font-weight: 500;
            font-size: 10px;
            text-transform: none;
            letter-spacing: 0.02em;
        }
        #wip-reengagement-toast {
            display: none;
            position: fixed;
            top: 64px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10001;
            max-width: min(520px, calc(100vw - 32px));
            background: linear-gradient(180deg, #450a0a 0%, #1e293b 100%);
            border: 1px solid #f87171;
            color: #fef2f2;
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 16px 48px rgba(0,0,0,0.55);
        }
        #wip-refresh-btn.is-spinning svg {
            animation: wip-spin 0.65s linear infinite;
        }
        @keyframes wip-spin { to { transform: rotate(360deg); } }
        #wip-ops-toast {
            display: none;
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 10000;
            max-width: min(520px, calc(100vw - 32px));
            background: #1e293b;
            border: 1px solid #334155;
            color: #f8fafc;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.45);
        }

        .wip-desktop-layout{display:block}.wip-main-column{min-width:0}
        .wip-board-layout{display:block}.wip-board-main{min-width:0}.wip-callback-column{min-width:0;position:sticky;top:14px;max-height:calc(100vh - 28px)}
        .wip-callback-column #wip-callback-section{margin:0;box-sizing:border-box;padding:0;overflow:hidden;border-color:rgba(59,130,246,.45);background:linear-gradient(180deg,rgba(30,58,138,.18) 0%,rgba(15,23,42,.78) 100%)}.wip-callback-column .wip-attention-header{padding:10px 11px 9px;margin:0;border-bottom:1px solid rgba(51,65,85,.65);background:rgba(15,23,42,.5)}.wip-callback-column #wip-callback-list{max-height:calc(100vh - 105px);overflow-y:auto;overscroll-behavior:contain;padding:7px;scrollbar-width:thin;scrollbar-color:#475569 transparent;display:flex;flex-direction:column;gap:6px}
        .wip-callback-item{display:block;border:1px solid rgba(51,65,85,.65);border-radius:8px;background:rgba(15,23,42,.76);padding:9px 10px;text-decoration:none;color:inherit;transition:border-color .15s ease,background .15s ease}.wip-callback-item:hover{border-color:#475569;background:#111b2d}.wip-callback-item.is-due{border-color:rgba(248,113,113,.45);background:rgba(69,10,10,.2)}.wip-callback-item__top{display:flex;align-items:flex-start;justify-content:space-between;gap:7px}.wip-callback-item__name{font-size:12px;font-weight:800;color:#f8fafc;line-height:1.25}.wip-callback-item__due{font-size:9px;font-weight:800;color:#94a3b8;white-space:nowrap}.wip-callback-item.is-due .wip-callback-item__due{color:#fecaca}.wip-callback-item__when{font-size:10px;font-weight:700;color:#93c5fd;margin-top:5px}.wip-callback-item__note{font-size:10px;line-height:1.35;color:#94a3b8;margin-top:5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .wip-queue-heading{display:flex;align-items:end;justify-content:space-between;gap:12px;border-top:1px solid rgba(51,65,85,.7);padding-top:18px;margin:22px 0 10px}.wip-queue-heading h2{margin:0;font-size:22px;letter-spacing:-.025em;color:#f8fafc}.wip-queue-heading p{font-size:12px;margin:4px 0 0;color:#64748b}.wip-queue-count{font-size:11px;color:#94a3b8;border:1px solid #334155;background:#111827;padding:5px 9px;border-radius:999px}
        .wip-filter-bar{padding:10px;border:1px solid rgba(51,65,85,.55);border-radius:10px;background:rgba(15,23,42,.45)}
        #wip-leads-grid{grid-template-columns:1fr;gap:8px!important}.wip-card{transition:border-color .15s ease,transform .15s ease,background .15s ease}.wip-card:hover{border-color:#475569;background:#111b2d}
        .wip-lead-row{position:relative;padding:10px 12px 10px 44px;gap:7px;border-radius:8px}.wip-lead-row.is-dragging{opacity:.42;border-style:dashed}.wip-lead-row.is-drop-target{border-color:#60a5fa}.wip-stack-handle{position:absolute;left:8px;top:10px;width:26px;height:34px;border:1px solid #334155;border-radius:6px;background:#0b1220;color:#64748b;font-size:20px;line-height:1;cursor:grab;display:flex;align-items:center;justify-content:center;user-select:none}.wip-stack-handle:active{cursor:grabbing}.wip-lead-row .wip-card__title a{font-size:15px}.wip-lead-row .wip-card__meta{padding-top:3px}.wip-lead-row .wip-card__controls{gap:7px}.wip-lead-row .wip-card__controls .status-select{flex:0 1 240px;min-width:180px;min-height:32px;padding:5px 8px;font-size:12px}
        .wip-stack-state{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:11px;color:#94a3b8}.wip-stack-state__pill{display:inline-flex;align-items:center;gap:4px;padding:3px 7px;border-radius:999px;border:1px solid #334155;background:#0b1220;color:#cbd5e1}.wip-stack-state__pill.is-due{border-color:#b45309;background:#451a03;color:#fde68a}.wip-stack-state__note{color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:560px}
        .wip-actioned-btn{margin-left:auto;min-height:32px;border:1px solid #2563eb;border-radius:7px;background:#172554;color:#bfdbfe;padding:6px 10px;font-size:11px;font-weight:800;cursor:pointer}.wip-actioned-btn:hover{background:#1e3a8a;border-color:#60a5fa}
        .wip-action-modal{display:none;position:fixed;inset:0;z-index:12100;background:rgba(2,6,23,.68);padding:18px;box-sizing:border-box}.wip-action-modal.is-open{display:block}.wip-action-modal__card{max-width:440px;margin:12vh auto 0;background:#111827;border:1px solid #374151;border-radius:14px;box-shadow:0 22px 60px rgba(0,0,0,.55);overflow:hidden}.wip-action-modal__head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid #334155}.wip-action-modal__title{font-size:17px;font-weight:800;color:#f8fafc}.wip-action-modal__sub{margin-top:3px;color:#64748b;font-size:11px}.wip-action-modal__close{width:32px;height:32px;border:1px solid #334155;border-radius:7px;background:#0f172a;color:#cbd5e1;cursor:pointer}.wip-action-modal__body{padding:15px}.wip-action-modal__field{margin-bottom:13px}.wip-action-modal__field label{display:block;margin-bottom:5px;color:#94a3b8;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.wip-action-modal__field select{width:100%;box-sizing:border-box;border:1px solid #334155;border-radius:8px;background:#0b1220;color:#f8fafc;padding:10px;font:inherit;font-size:13px}.wip-action-modal__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.wip-action-modal__cancel,.wip-action-modal__save{border-radius:8px;padding:8px 12px;font-size:12px;font-weight:800;cursor:pointer}.wip-action-modal__cancel{border:1px solid #334155;background:#111827;color:#cbd5e1}.wip-action-modal__save{border:1px solid #2563eb;background:#2563eb;color:#fff}
        @media(max-width:1100px){}@media(max-width:820px){.wip-page{padding-left:16px!important;padding-right:16px!important}.wip-board-layout{grid-template-columns:1fr}.wip-callback-column{position:static;max-height:none;order:-1}.wip-callback-column #wip-callback-list{max-height:320px}.wip-lead-row{padding-left:42px}.wip-actioned-btn{margin-left:0}}

        /* Live WIP dashboard */
        .wip-page{max-width:1920px!important}
        .wip-live-meta{display:flex;align-items:center;gap:8px;color:#64748b;font-size:11px}
        .wip-live-indicator{display:inline-flex;align-items:center;gap:6px;padding:5px 8px;border:1px solid rgba(34,197,94,.35);border-radius:999px;background:rgba(20,83,45,.22);color:#86efac;font-weight:800;letter-spacing:.05em}
        .wip-live-indicator.is-error{border-color:rgba(248,113,113,.55);background:rgba(127,29,29,.25);color:#fecaca}
        .wip-live-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 0 rgba(34,197,94,.5);animation:wip-live-pulse 2s infinite}
        .wip-live-indicator.is-error .wip-live-dot{background:#ef4444;animation:none}
        @keyframes wip-live-pulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.45)}70%{box-shadow:0 0 0 7px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
        .wip-next-sip-banner{position:sticky;top:8px;z-index:80;display:grid;grid-template-columns:auto minmax(0,1fr) auto auto;align-items:center;gap:12px;margin:0 0 12px;padding:11px 13px;border:1px solid #f59e0b;border-radius:12px;background:linear-gradient(90deg,rgba(120,53,15,.96),rgba(30,41,59,.97));box-shadow:0 10px 30px rgba(0,0,0,.35)}
        .wip-next-sip-banner.is-due{border-color:#ef4444;background:linear-gradient(90deg,rgba(127,29,29,.98),rgba(30,41,59,.98))}
        .wip-next-sip-banner__pulse{width:11px;height:11px;border-radius:50%;background:#fbbf24;box-shadow:0 0 0 0 rgba(251,191,36,.55);animation:wip-sip-pulse 1.5s infinite}
        .wip-next-sip-banner.is-due .wip-next-sip-banner__pulse{background:#f87171}
        @keyframes wip-sip-pulse{0%{box-shadow:0 0 0 0 rgba(251,191,36,.55)}70%{box-shadow:0 0 0 9px rgba(251,191,36,0)}100%{box-shadow:0 0 0 0 rgba(251,191,36,0)}}
        .wip-next-sip-banner__copy{display:flex;align-items:baseline;gap:8px;min-width:0;flex-wrap:wrap}.wip-next-sip-banner__eyebrow{font-size:9px;font-weight:900;letter-spacing:.1em;color:#fde68a}.wip-next-sip-banner__copy strong{font-size:14px;color:#fff}.wip-next-sip-banner__copy span:last-child{font-size:11px;color:#cbd5e1}
        .wip-next-sip-banner__countdown{font-size:14px;font-weight:900;color:#fde68a;white-space:nowrap}.wip-next-sip-banner.is-due .wip-next-sip-banner__countdown{color:#fecaca}.wip-next-sip-banner__open{padding:6px 9px;border-radius:7px;border:1px solid rgba(255,255,255,.2);background:rgba(15,23,42,.55);color:#fff;text-decoration:none;font-size:11px;font-weight:800}
        .wip-dashboard-grid{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(0,1fr) minmax(0,.92fr);gap:12px;align-items:start}
        .wip-lane,.wip-other-statuses{min-width:0;border:1px solid #263244;border-radius:13px;background:rgba(15,23,42,.56);overflow:hidden}
        .wip-lane--priority{border-color:rgba(245,158,11,.42);background:linear-gradient(180deg,rgba(120,53,15,.12),rgba(15,23,42,.64) 160px)}
        .wip-lane--active{border-color:rgba(59,130,246,.35)}.wip-lane--dmp{border-color:rgba(168,85,247,.35)}
        .wip-lane__head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;padding:12px 13px;border-bottom:1px solid rgba(51,65,85,.7);background:rgba(15,23,42,.78);position:sticky;top:0;z-index:5}
        .wip-lane__head h3{margin:2px 0 0;font-size:17px;letter-spacing:-.02em;color:#f8fafc}.wip-lane__head p{margin:3px 0 0;font-size:10px;color:#64748b}.wip-lane__eyebrow{display:block;font-size:8px;font-weight:900;letter-spacing:.11em;color:#64748b}.wip-lane--priority .wip-lane__eyebrow{color:#fbbf24}.wip-lane--dmp .wip-lane__eyebrow{color:#c4b5fd}
        .wip-lane__count{min-width:25px;height:25px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #334155;border-radius:999px;background:#0b1220;color:#cbd5e1;font-size:10px;font-weight:800}
        .wip-lane__stack{display:flex;flex-direction:column;gap:7px;padding:8px;min-height:84px}.wip-lane__empty{padding:24px 10px;text-align:center;color:#475569;font-size:11px;border:1px dashed #263244;border-radius:9px}
        .wip-other-statuses{margin-top:12px}.wip-lane__stack--other{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}
        .wip-lead-row{padding:9px 9px 9px 39px!important;gap:6px!important;border-radius:9px!important;transition:border-color .18s ease,background .18s ease,box-shadow .18s ease,transform .25s ease!important}
        .wip-stack-handle{left:7px!important;top:9px!important;width:24px!important;height:32px!important}
        .wip-lead-row .wip-card__title a{font-size:14px!important}.wip-lead-row .wip-card__meta{font-size:10px!important}.wip-lead-row .wip-card__controls .status-select{min-width:0!important;flex:1!important}
        .wip-sip-panel{margin:2px 0;padding:9px 10px;border:1px solid rgba(245,158,11,.5);border-radius:8px;background:linear-gradient(135deg,rgba(120,53,15,.55),rgba(30,41,59,.7));box-shadow:inset 3px 0 0 #f59e0b}
        .wip-sip-panel__label{font-size:9px;font-weight:950;letter-spacing:.11em;color:#fbbf24}.wip-sip-panel__main{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:3px}.wip-sip-panel__time{font-size:16px;font-weight:900;color:#fff}.wip-sip-panel__prep{font-size:11px;font-weight:850;color:#fde68a;margin-top:1px}.wip-sip-panel__prep [data-sip-countdown]{margin-left:4px;color:#fbbf24}.wip-sip-panel__actions{display:flex;align-items:center;justify-content:flex-end;gap:5px;flex-wrap:wrap}
        .wip-sip-prep-btn,.wip-sip-edit-btn{border-radius:6px;padding:5px 7px;font-size:9px;font-weight:900;cursor:pointer}.wip-sip-prep-btn{border:1px solid #f59e0b;background:#78350f;color:#fef3c7}.wip-sip-edit-btn{border:1px solid #475569;background:#111827;color:#cbd5e1}.wip-sip-edit-btn.is-primary{border-color:#f59e0b;color:#fef3c7}.wip-sip-done{font-size:9px;font-weight:900;color:#86efac}.wip-sip-panel__missing{font-size:10px;color:#fecaca;line-height:1.3}
        .wip-card-sip-upcoming{border-color:rgba(245,158,11,.55)!important}.wip-card-sip-soon{border-color:#f59e0b!important;box-shadow:0 0 0 1px rgba(245,158,11,.15),0 8px 22px rgba(120,53,15,.12)}.wip-card-sip-urgent{border-color:#fb923c!important;background:rgba(124,45,18,.16)!important;box-shadow:0 0 0 1px rgba(251,146,60,.2)}.wip-card-sip-due{border-color:#ef4444!important;background:rgba(127,29,29,.22)!important;box-shadow:0 0 0 1px rgba(239,68,68,.22),0 10px 30px rgba(127,29,29,.2)}.wip-card-sip-due .wip-sip-panel{border-color:#ef4444;box-shadow:inset 3px 0 0 #ef4444}.wip-card-sip-due .wip-sip-panel__label,.wip-card-sip-due .wip-sip-panel__prep,.wip-card-sip-due .wip-sip-panel__prep [data-sip-countdown]{color:#fecaca}.wip-card-sip-prepped .wip-sip-panel{border-color:rgba(34,197,94,.45);box-shadow:inset 3px 0 0 #22c55e;background:rgba(20,83,45,.13)}.wip-card-sip-missing{border-color:#ef4444!important}
        .wip-callback-strip{display:flex;align-items:center;gap:6px;min-width:0;margin:1px 0;padding:6px 8px;border:1px solid rgba(59,130,246,.38);border-radius:7px;background:rgba(30,64,175,.16);font-size:10px;color:#bfdbfe}.wip-callback-strip__label{font-size:8px;font-weight:900;letter-spacing:.08em;color:#93c5fd}.wip-callback-strip strong{white-space:nowrap;color:#dbeafe}.wip-callback-strip [data-callback-countdown]{font-weight:800;margin-left:auto;white-space:nowrap}.wip-callback-strip__note{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#94a3b8;max-width:120px}
        .wip-lead-row.is-callback-soon{border-color:rgba(59,130,246,.65)!important}.wip-lead-row.is-callback-urgent{border-color:#3b82f6!important;background:rgba(30,64,175,.16)!important}.wip-lead-row.is-callback-due{border-color:#ef4444!important;background:rgba(127,29,29,.17)!important}.wip-lead-row.is-callback-due .wip-callback-strip{border-color:#ef4444;background:rgba(127,29,29,.23);color:#fecaca}
        .wip-actioned-btn{padding:6px 8px!important;white-space:nowrap}.wip-stack-state{font-size:10px!important}.wip-stack-state__pill{padding:2px 6px!important}
        .wip-sip-modal .wip-action-modal__card{max-width:470px}.wip-sip-modal input[type="datetime-local"]{width:100%;box-sizing:border-box;border:1px solid #334155;border-radius:8px;background:#0b1220;color:#f8fafc;padding:10px;font:inherit;font-size:13px;color-scheme:dark}
        @media(max-width:1280px){.wip-dashboard-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wip-lane--dmp{grid-column:1/-1}.wip-lane--dmp .wip-lane__stack{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:820px){.wip-dashboard-grid{grid-template-columns:1fr}.wip-lane--dmp{grid-column:auto}.wip-lane--dmp .wip-lane__stack,.wip-lane__stack--other{display:flex}.wip-next-sip-banner{position:static;grid-template-columns:auto 1fr auto}.wip-next-sip-banner__open{display:none}.wip-next-sip-banner__copy span:last-child{width:100%}}
</style>
</head>
<body style="margin:0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#0a0f1a; color:#f9fafb; min-height:100vh;">

<div class="wip-page" style="max-width:1680px; margin:0 auto; padding:18px 28px 28px; box-sizing:border-box;">

    @include('partials.app-nav')
    @include('partials.lead-click-to-call', ['lead' => null, 'variant' => 'icon'])

    <div class="wip-desktop-layout">
    <main class="wip-main-column">
    <header class="wip-header-block">
        @php
            $attentionCount = count($remarketing_response_events ?? []);
        @endphp
        <div class="wip-header-toolbar">
            <div>
                <div class="wip-header-title">Jinx Workdesk</div>
                <div class="wip-header-sub">Assistant, callbacks and case priorities</div>
            </div>
            <button type="button" id="wip-refresh-btn" class="wip-refresh-btn" title="Reload">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M21 12a9 9 0 1 1-2.64-6.36"/>
                    <path d="M21 3v7h-7"/>
                </svg>
            </button>
        </div>
        <div class="wip-header-scope-row">
            <div class="wip-scope-segment" role="group" aria-label="Queue scope">
                <a
                    href="{{ url('/wip?show=active') }}"
                    class="wip-scope-segment__link {{ $show === 'active' ? 'wip-scope-segment__link--active' : '' }}"
                    @if ($show === 'active') aria-current="page" @endif
                >Active</a>
                <a
                    href="{{ url('/wip?show=all') }}"
                    class="wip-scope-segment__link {{ $show === 'all' ? 'wip-scope-segment__link--active' : '' }}"
                    @if ($show === 'all') aria-current="page" @endif
                >All</a>
            </div>
            @if ($attentionCount > 0)
                <span class="wip-attention-badge" title="Inbound responses waiting for review">
                    ⚠️ {{ $attentionCount }} Attention Required
                </span>
            @endif
        </div>
    </header>

    <div id="wip-ops-toast" role="status"></div>
    <div id="wip-reengagement-toast" role="alert"></div>

    @if (session('success'))
        <div class="wip-flash" role="status">{{ session('success') }}</div>
    @endif

    @php $scheduledCallbacks = $scheduled_callbacks ?? []; @endphp
    <div class="wip-board-layout">
      <div class="wip-board-main">

    <section id="wip-attention-required-section" aria-labelledby="wip-attention-required-heading" class="wip-attention-section {{ $attentionCount > 0 ? 'wip-attention-section--active' : '' }}" style="{{ $attentionCount > 0 ? '' : 'display:none;' }}">
        <div class="wip-attention-header">
            <h2 id="wip-attention-required-heading" class="wip-attention-title">⚠️ Attention Required</h2>
            @if ($attentionCount > 0)
                <span class="wip-attention-count">{{ $attentionCount }}</span>
            @endif
        </div>
        @if ($attentionCount > 0)
            <div class="wip-attention-grid">
                @foreach(($remarketing_response_events ?? []) as $event)
                    @php
                        $channelRaw = strtolower((string) ($event['channel'] ?? ''));
                        $channelLabel = match ($channelRaw) {
                            'whatsapp' => '🟢 WhatsApp',
                            'sms' => '💬 SMS',
                            'email' => '✉️ Email',
                            'call' => '📞 Call',
                            default => \Illuminate\Support\Str::title(str_replace('_', ' ', $channelRaw ?: 'unknown')),
                        };
                        $preview = \Illuminate\Support\Str::limit((string) ($event['message_preview'] ?? ''), 180);
                        $phone = trim((string) ($event['phone'] ?? ''));
                        $callFrom = trim((string) ($event['call_from_phone'] ?? ''));
                        $callTo = trim((string) ($event['call_to_phone'] ?? ''));
                        $displayPreview = $preview !== '' ? $preview : '-';
                        if ($channelRaw === 'call') {
                            $callerText = $callFrom !== '' ? $callFrom : 'Unknown';
                            $dialledText = $callTo !== '' ? $callTo : 'Unknown';
                            $displayPreview = 'Inbound call received. Caller: '.$callerText.'. Dialled: '.$dialledText.'.';
                        }
                    @endphp
                    <article class="wip-card wip-card-attention">
                        <div class="wip-card__row1">
                            <div class="wip-card__title">
                                @if (!empty($event['jinx_lead_id']))
                                    <a href="{{ url('/lead/' . $event['jinx_lead_id']) }}">{{ $event['lead_name'] }}</a>
                                @else
                                    <span>{{ $event['lead_name'] }}</span>
                                @endif
                            </div>
                            <div class="wip-card-actions">
                                <span class="wip-chip wip-channel-chip">{{ $channelLabel }}</span>
                            </div>
                        </div>
                        <div class="wip-card__meta">
                            <span class="wip-meta-k">Lead ID</span> <span class="wip-meta-v">{{ $event['lead_id'] }}</span>
                            <span class="wip-meta-dot" aria-hidden="true">·</span>
                            <span class="wip-meta-k">Detected</span> <span class="wip-meta-v">{{ $event['detected_text'] ?? 'Just now' }}</span>
                            @if ($phone !== '')
                                <span class="wip-meta-dot" aria-hidden="true">·</span>
                                <span class="wip-meta-k">Phone</span> <span class="wip-meta-v">{{ $phone }}</span>
                            @endif
                        </div>
                        <div class="wip-card__meta" style="padding-top:0; border-top:none;">
                            <span class="wip-meta-k">Preview</span>
                            <span class="wip-meta-v">{{ $displayPreview }}</span>
                        </div>
                        <div class="wip-card-actions" style="justify-content:flex-start; max-width:none;">
                            @if (!empty($event['jinx_lead_id']))
                                <a href="{{ url('/lead/' . $event['jinx_lead_id']) }}" class="wip-attention-btn">OPEN LEAD</a>
                            @endif

                            <form class="wip-action-form" method="POST" action="{{ route('remarketing.response.handle', ['id' => $event['id']]) }}">
                                @csrf
                                <input type="hidden" name="decision" value="awaiting_call">
                                <button type="submit" class="wip-attention-btn">Awaiting Call</button>
                            </form>

                            <form class="wip-action-form" method="POST" action="{{ route('remarketing.response.handle', ['id' => $event['id']]) }}">
                                @csrf
                                <input type="hidden" name="decision" value="initial_assessment">
                                <button type="submit" class="wip-attention-btn">Initial Assessment</button>
                            </form>

                            <form class="wip-action-form" method="POST" action="{{ route('remarketing.response.handle', ['id' => $event['id']]) }}">
                                @csrf
                                <input type="hidden" name="decision" value="callback">
                                <button type="submit" class="wip-attention-btn">Callback</button>
                            </form>

                            <form class="wip-action-form" method="POST" action="{{ route('remarketing.response.handle', ['id' => $event['id']]) }}">
                                @csrf
                                <input type="hidden" name="decision" value="dead">
                                <button type="submit" class="wip-attention-btn wip-attention-btn--dead">Dead</button>
                            </form>

                            <form class="wip-action-form" method="POST" action="{{ route('remarketing.response.handle', ['id' => $event['id']]) }}">
                                @csrf
                                <input type="hidden" name="decision" value="ignore">
                                <button type="submit" class="wip-attention-btn wip-attention-btn--ignore">Ignore</button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>
        @else
            <div class="wip-attention-empty">No inbound responses waiting for review.</div>
        @endif
    </section>

    <div class="wip-queue-heading">
        <div>
            <h2>Live workdesk</h2>
            <p>Three independent working stacks. Timed SIP prep calls and callbacks rise automatically as they become due.</p>
        </div>
        <div class="wip-live-meta">
            <span id="wip-live-indicator" class="wip-live-indicator"><span class="wip-live-dot"></span> LIVE</span>
            <span id="wip-live-updated">Updated just now</span>
        </div>
    </div>

    <div id="wip-next-sip-banner" class="wip-next-sip-banner" style="display:none" aria-live="polite">
        <div class="wip-next-sip-banner__pulse"></div>
        <div class="wip-next-sip-banner__copy">
            <span class="wip-next-sip-banner__eyebrow">NEXT SIP PREP</span>
            <strong data-next-sip-name>—</strong>
            <span data-next-sip-times>—</span>
        </div>
        <div class="wip-next-sip-banner__countdown" data-next-sip-countdown>—</div>
        <a href="#" class="wip-next-sip-banner__open" data-next-sip-open>Open case</a>
    </div>

    <div id="wip-filter-bar" class="wip-filter-bar">
        <input type="search" id="wip-filter-name" autocomplete="off" placeholder="Filter by name…" aria-label="Filter leads by name">
        <select id="wip-filter-status" aria-label="Filter by status">
            <option value="">All statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status }}">{{ $status }}</option>
            @endforeach
        </select>
        <select id="wip-filter-source" aria-label="Filter by source">
            <option value="">All sources</option>
            @foreach($wip_source_filter_options ?? [] as $rawSource)
                @if($rawSource === '')
                    <option value="__EMPTY__">{{ \App\Support\LeadSourceDisplay::label(null) }}</option>
                @else
                    <option value="{{ $rawSource }}">{{ \App\Support\LeadSourceDisplay::label($rawSource) }}</option>
                @endif
            @endforeach
        </select>
    </div>

    <div id="wip-filter-no-matches" style="display:none; margin-bottom:12px; background:#111827; border:1px solid #374151; border-radius:16px; padding:18px; color:#9ca3af;">
        No cases match the current filters.
    </div>

    @php
        $unseenReengagementLeadSet = $unseen_reengagement_lead_set ?? [];
        $reengagementChannelByLeadId = $reengagement_channel_by_lead_id ?? [];
        $formatReengagementChannel = static function (?string $raw): string {
            if ($raw === null || trim($raw) === '') {
                return '';
            }
            $k = strtolower(trim($raw));
            return match ($k) {
                'whatsapp' => 'WhatsApp',
                'sms' => 'SMS',
                'email' => 'Email',
                'call' => 'Call',
                default => \Illuminate\Support\Str::title(str_replace('_', ' ', $k)),
            };
        };

        $priorityLeads = $leads->filter(fn ($lead) => in_array($lead->wip_status, ['SIP Booked', 'Ready to Refer'], true))->values();
        $activeLeads = $leads->filter(fn ($lead) => in_array($lead->wip_status, ['New Lead', 'Collecting Docs', 'Callback Set'], true))->values();
        $dmpLeads = $leads->filter(fn ($lead) => $lead->wip_status === 'DMP Transfer')->values();
        $primaryLeadIds = $priorityLeads->concat($activeLeads)->concat($dmpLeads)->pluck('id')->all();
        $otherLeads = $leads->reject(fn ($lead) => in_array($lead->id, $primaryLeadIds, true))->values();
    @endphp

    <div id="wip-dashboard-grid" class="wip-dashboard-grid">
        <section class="wip-lane wip-lane--priority" data-wip-lane="priority">
            <div class="wip-lane__head">
                <div>
                    <span class="wip-lane__eyebrow">DO NOT MISS</span>
                    <h3>Priority</h3>
                    <p>SIP Booked + Ready to Refer</p>
                </div>
                <span class="wip-lane__count" data-lane-count="priority">{{ $priorityLeads->count() }}</span>
            </div>
            <div id="wip-priority-stack" class="wip-lane__stack" data-stack-key="priority">
                @forelse($priorityLeads as $lead)
                    @include('wip.partials.lead-card', ['lead' => $lead])
                @empty
                    <div class="wip-lane__empty" data-lane-empty>No priority cases.</div>
                @endforelse
            </div>
        </section>

        <section class="wip-lane wip-lane--active" data-wip-lane="active">
            <div class="wip-lane__head">
                <div>
                    <span class="wip-lane__eyebrow">WORKING QUEUE</span>
                    <h3>Active Cases</h3>
                    <p>New leads · collecting docs · callbacks</p>
                </div>
                <span class="wip-lane__count" data-lane-count="active">{{ $activeLeads->count() }}</span>
            </div>
            <div id="wip-active-stack" class="wip-lane__stack" data-stack-key="active">
                @forelse($activeLeads as $lead)
                    @include('wip.partials.lead-card', ['lead' => $lead])
                @empty
                    <div class="wip-lane__empty" data-lane-empty>No active cases.</div>
                @endforelse
            </div>
        </section>

        <section class="wip-lane wip-lane--dmp" data-wip-lane="dmp">
            <div class="wip-lane__head">
                <div>
                    <span class="wip-lane__eyebrow">DMP</span>
                    <h3>DMP Transfers</h3>
                    <p>Dedicated transfer stack</p>
                </div>
                <span class="wip-lane__count" data-lane-count="dmp">{{ $dmpLeads->count() }}</span>
            </div>
            <div id="wip-dmp-stack" class="wip-lane__stack" data-stack-key="dmp">
                @forelse($dmpLeads as $lead)
                    @include('wip.partials.lead-card', ['lead' => $lead])
                @empty
                    <div class="wip-lane__empty" data-lane-empty>No DMP transfers.</div>
                @endforelse
            </div>
        </section>
    </div>

    @if ($otherLeads->isNotEmpty())
        <section class="wip-other-statuses">
            <div class="wip-lane__head">
                <div><span class="wip-lane__eyebrow">ALL VIEW</span><h3>Other statuses</h3></div>
                <span class="wip-lane__count">{{ $otherLeads->count() }}</span>
            </div>
            <div id="wip-other-stack" class="wip-lane__stack wip-lane__stack--other" data-stack-key="other">
                @foreach($otherLeads as $lead)
                    @include('wip.partials.lead-card', ['lead' => $lead])
                @endforeach
            </div>
        </section>
    @endif
      </div>


    </div>
    </main>
    </div>
</div>

@include('wip.assistant')

<div id="wip-actioned-modal" class="wip-action-modal" aria-hidden="true">
    <div class="wip-action-modal__card" role="dialog" aria-modal="true" aria-labelledby="wip-actioned-title">
        <div class="wip-action-modal__head">
            <div>
                <div id="wip-actioned-title" class="wip-action-modal__title">Case actioned</div>
                <div id="wip-actioned-case-name" class="wip-action-modal__sub">What are you waiting on now?</div>
            </div>
            <button type="button" id="wip-actioned-close" class="wip-action-modal__close" aria-label="Close">×</button>
        </div>
        <form id="wip-actioned-form" class="wip-action-modal__body">
            <div class="wip-action-modal__field">
                <label for="wip-actioned-waiting-on">Waiting on</label>
                <select id="wip-actioned-waiting-on" required>
                    <option value="">Choose…</option>
                    <option value="client">Client</option>
                    <option value="creditor">Creditor</option>
                    <option value="ip_provider">IP / IVA provider</option>
                    <option value="documents">Documents</option>
                    <option value="internal">Internal action</option>
                    <option value="review_later">Nothing / review later</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="wip-action-modal__actions">
                <button type="button" id="wip-actioned-cancel" class="wip-action-modal__cancel">Cancel</button>
                <button type="submit" id="wip-actioned-save" class="wip-action-modal__save">Move to bottom</button>
            </div>
        </form>
    </div>
</div>

<div id="wip-sip-modal" class="wip-action-modal wip-sip-modal" aria-hidden="true">
    <div class="wip-action-modal__card" role="dialog" aria-modal="true" aria-labelledby="wip-sip-title">
        <div class="wip-action-modal__head">
            <div>
                <div id="wip-sip-title" class="wip-action-modal__title">SIP appointment</div>
                <div id="wip-sip-case-name" class="wip-action-modal__sub">Set the booked appointment time.</div>
            </div>
            <button type="button" id="wip-sip-close" class="wip-action-modal__close" aria-label="Close">×</button>
        </div>
        <form id="wip-sip-form" class="wip-action-modal__body">
            <div class="wip-action-modal__field">
                <label for="wip-sip-booked-at">Appointment date & time</label>
                <input id="wip-sip-booked-at" type="datetime-local" required>
            </div>
            <div style="font-size:11px;color:#94a3b8;line-height:1.45;">Jinx will automatically make the prep call due 15 minutes before this appointment and keep it visible until prep is marked complete.</div>
            <div class="wip-action-modal__actions">
                <button type="button" id="wip-sip-cancel" class="wip-action-modal__cancel">Cancel</button>
                <button type="submit" id="wip-sip-save" class="wip-action-modal__save">Save SIP time</button>
            </div>
        </form>
    </div>
</div>

<div id="checklist-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:9999; padding:16px; box-sizing:border-box;">
    <div style="max-width:720px; margin:30px auto; background:#111827; border:1px solid #374151; border-radius:18px; overflow:hidden;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px; border-bottom:1px solid #374151;">
            <div>
                <div id="modal-case-name" style="font-size:20px; font-weight:700;">Checklist</div>
                <div id="modal-subtitle" style="font-size:12px; color:#9ca3af; margin-top:4px;">0 outstanding</div>
            </div>
            <button type="button" id="close-modal-btn" style="background:#1f2937; color:#fff; border:1px solid #374151; border-radius:10px; padding:10px 12px; cursor:pointer;">Close</button>
        </div>

        <div style="padding:16px;">
            <div style="display:flex; gap:8px; margin-bottom:14px;">
                <input
                    type="text"
                    id="new-item-name"
                    placeholder="Add checklist item"
                    style="flex:1; background:#0f172a; color:#fff; border:1px solid #374151; border-radius:10px; padding:12px; font-size:14px;"
                >
                <button
                    type="button"
                    id="add-item-btn"
                    style="background:#10b981; color:#fff; border:none; border-radius:10px; padding:12px 14px; font-weight:700; cursor:pointer;">
                    Add
                </button>
            </div>

            <div id="checklist-items" style="display:flex; flex-direction:column; gap:10px;"></div>
        </div>
    </div>
</div>

<script>
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const wipReengagementSnapshotIds = new Set(@json($unseen_reengagement_event_ids ?? []));
    const wipReengagementPollUrl = @json(route('wip.reengagement-poll'));
    const wipReengagementAckUrl = @json(route('wip.reengagement-acknowledge'));
    const wipStackReorderUrl = @json(route('wip.stack.reorder'));
    const wipCallbackInitialDueIds = new Set(@json(collect($scheduled_callbacks ?? [])->where('due', true)->pluck('callback_id')->values()->all()));

    (function () {
        let wipAlertAudioCtx = null;

        function getWipAlertAudioContext() {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) {
                return null;
            }
            if (!wipAlertAudioCtx) {
                wipAlertAudioCtx = new AC();
            }
            return wipAlertAudioCtx;
        }

        function unlockWipAlertAudio() {
            const ctx = getWipAlertAudioContext();
            if (!ctx) {
                return;
            }
            if (ctx.state === 'suspended') {
                ctx.resume().catch(function () {});
            }
        }

        ['click', 'keydown', 'touchstart'].forEach(function (ev) {
            document.addEventListener(ev, unlockWipAlertAudio, { passive: true, capture: true });
        });

        window.jinxWipPlayReengagementAlertSound = function () {
            try {
                const ctx = getWipAlertAudioContext();
                if (!ctx) {
                    return;
                }
                const playTone = function () {
                    [523.25, 659.25].forEach(function (freq, i) {
                        const o = ctx.createOscillator();
                        const g = ctx.createGain();
                        o.type = 'sine';
                        o.frequency.value = freq;
                        g.gain.value = 0.05;
                        o.connect(g);
                        g.connect(ctx.destination);
                        const t0 = ctx.currentTime + i * 0.11;
                        o.start(t0);
                        o.stop(t0 + 0.18);
                    });
                };
                if (ctx.state === 'suspended') {
                    ctx.resume().then(playTone).catch(function () {});
                } else {
                    playTone();
                }
            } catch (e) {}
        };
    })();

    const wipFilterNameInput = document.getElementById('wip-filter-name');
    const wipFilterStatus = document.getElementById('wip-filter-status');
    const wipFilterSource = document.getElementById('wip-filter-source');
    const wipFilterNoMatches = document.getElementById('wip-filter-no-matches');

    function applyWipFilters() {
        if (!wipFilterNameInput || !wipFilterStatus || !wipFilterSource) return;

        const q = (wipFilterNameInput.value || '').trim().toLowerCase();
        const st = wipFilterStatus.value;
        const src = wipFilterSource.value;

        const rows = document.querySelectorAll('.wip-lead-row');
        let visible = 0;

        rows.forEach(function (card) {
            const name = card.dataset.leadName || '';
            const okName = !q || name.includes(q);
            const okStatus = !st || card.dataset.wipStatus === st;
            let okSource = true;
            if (src) {
                if (src === '__EMPTY__') {
                    okSource = (card.dataset.leadSource || '') === '';
                } else {
                    okSource = (card.dataset.leadSource || '') === src;
                }
            }
            const show = okName && okStatus && okSource;
            card.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (wipFilterNoMatches) {
            wipFilterNoMatches.style.display = (rows.length > 0 && visible === 0) ? 'block' : 'none';
        }
    }

    if (wipFilterNameInput) {
        wipFilterNameInput.addEventListener('input', applyWipFilters);
    }
    if (wipFilterStatus) {
        wipFilterStatus.addEventListener('change', applyWipFilters);
    }
    if (wipFilterSource) {
        wipFilterSource.addEventListener('change', applyWipFilters);
    }

    (function () {
        const dashboard = document.getElementById('wip-dashboard-grid');
        if (!dashboard) return;

        let draggedCard = null;
        let draggedStack = null;
        let reorderInFlight = false;

        function filtersAreActive() {
            return !!((wipFilterNameInput?.value || '').trim() || wipFilterStatus?.value || wipFilterSource?.value);
        }

        function toast(message) {
            const el = document.getElementById('wip-ops-toast');
            if (!el) return;
            el.textContent = message;
            el.style.display = 'block';
            window.setTimeout(() => { el.style.display = 'none'; }, 3000);
        }
        window.jinxWipToast = toast;

        function callbackWasActioned(card) {
            if (!card?.dataset.callbackAt || !card.dataset.lastActionedAt) return false;
            const callbackAt = new Date(card.dataset.callbackAt).getTime();
            const actionedAt = new Date(card.dataset.lastActionedAt).getTime();
            return Number.isFinite(callbackAt) && Number.isFinite(actionedAt) && actionedAt >= callbackAt;
        }

        window.jinxWipCallbackWasActioned = callbackWasActioned;

        function timedOrderingOwnsCard(card) {
            if (!card) return false;
            if (card.dataset.wipStatus === 'SIP Booked' && !card.dataset.sipPrepCompletedAt) return true;
            const raw = card.dataset.callbackAt;
            if (!raw || callbackWasActioned(card)) return false;
            const ms = new Date(raw).getTime() - Date.now();
            return Number.isFinite(ms) && ms <= 60 * 60 * 1000;
        }

        async function persistOrder() {
            if (reorderInFlight) return;
            reorderInFlight = true;
            const leadIds = Array.from(document.querySelectorAll('[data-stack-key] .wip-lead-row'))
                .map(card => Number(card.dataset.leadId))
                .filter(Boolean);
            if (!leadIds.length) {
                reorderInFlight = false;
                return;
            }

            try {
                const response = await fetch(wipStackReorderUrl, {
                    method: 'PATCH',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ lead_ids: leadIds }),
                });
                if (!response.ok) throw new Error('Could not save stack order');
                leadIds.forEach((id, index) => {
                    const card = document.querySelector('.wip-lead-row[data-lead-id="' + id + '"]');
                    if (card) card.dataset.queuePosition = String((index + 1) * 1000);
                });
                toast('Stack order saved.');
            } catch (error) {
                toast('Could not save stack order. Refreshing…');
                window.setTimeout(() => window.jinxWipSyncDashboard?.(true), 900);
            } finally {
                reorderInFlight = false;
            }
        }
        window.jinxWipPersistOrder = persistOrder;

        dashboard.addEventListener('dragstart', event => {
            const handle = event.target.closest('.wip-stack-handle');
            if (!handle) return;

            if (filtersAreActive()) {
                event.preventDefault();
                toast('Clear the filters before reordering a stack.');
                return;
            }

            draggedCard = handle.closest('.wip-lead-row');
            draggedStack = draggedCard?.closest('[data-stack-key]') || null;
            if (!draggedCard || !draggedStack) return;

            if (timedOrderingOwnsCard(draggedCard)) {
                event.preventDefault();
                toast('This timed card is being positioned automatically.');
                draggedCard = null;
                draggedStack = null;
                return;
            }

            draggedCard.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', draggedCard.dataset.leadId || '');
        });

        dashboard.addEventListener('dragend', async () => {
            if (!draggedCard) return;
            draggedCard.classList.remove('is-dragging');
            draggedStack?.querySelectorAll('.is-drop-target').forEach(row => row.classList.remove('is-drop-target'));
            draggedCard = null;
            draggedStack = null;
            await persistOrder();
            window.jinxWipRunTimeEngine?.();
        });

        dashboard.addEventListener('dragover', event => {
            if (!draggedCard || !draggedStack) return;
            const overStack = event.target.closest('[data-stack-key]');
            if (overStack !== draggedStack) return;

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const cards = Array.from(draggedStack.querySelectorAll('.wip-lead-row:not(.is-dragging)'))
                .filter(card => card.style.display !== 'none' && !timedOrderingOwnsCard(card));

            let after = null;
            let bestOffset = Number.NEGATIVE_INFINITY;
            cards.forEach(card => {
                const box = card.getBoundingClientRect();
                const offset = event.clientY - box.top - box.height / 2;
                if (offset < 0 && offset > bestOffset) {
                    bestOffset = offset;
                    after = card;
                }
            });

            draggedStack.querySelectorAll('.is-drop-target').forEach(row => row.classList.remove('is-drop-target'));
            if (after) {
                after.classList.add('is-drop-target');
                draggedStack.insertBefore(draggedCard, after);
            } else {
                draggedStack.appendChild(draggedCard);
            }
        });

        const modal = document.getElementById('wip-actioned-modal');
        const form = document.getElementById('wip-actioned-form');
        const caseName = document.getElementById('wip-actioned-case-name');
        const waitingOn = document.getElementById('wip-actioned-waiting-on');
        const save = document.getElementById('wip-actioned-save');
        const close = document.getElementById('wip-actioned-close');
        const cancel = document.getElementById('wip-actioned-cancel');
        let actionLeadId = null;

        function closeActionedModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            actionLeadId = null;
        }

        function openActionedModal(button) {
            actionLeadId = Number(button.dataset.actionedLeadId);
            caseName.textContent = (button.dataset.actionedCaseName || 'Case') + ' — what are you waiting on now?';
            waitingOn.value = '';
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            window.setTimeout(() => waitingOn.focus(), 0);
        }

        function renderQueueState(card, item) {
            const wrap = card.querySelector('[data-stack-state]');
            if (!wrap) return;
            const waiting = item.waiting_on_label
                ? '<span class="wip-stack-state__pill">Waiting on: <strong>' + escapeHtml(item.waiting_on_label) + '</strong></span>'
                : '<span class="wip-stack-state__pill">Not actioned yet</span>';
            const chase = item.next_chase_display
                ? '<span class="wip-stack-state__pill' + (item.chase_due ? ' is-due' : '') + '">' + (item.chase_due ? 'Chase due' : 'Chase') + ': ' + escapeHtml(item.next_chase_display) + '</span>'
                : '';
            const actioned = item.last_actioned_display
                ? '<span class="wip-stack-state__pill">Last actioned ' + escapeHtml(item.last_actioned_display) + '</span>'
                : '';
            const actionNote = item.action_note
                ? '<span class="wip-stack-state__note" title="' + escapeHtml(item.action_note) + '">' + escapeHtml(item.action_note) + '</span>'
                : '';
            wrap.innerHTML = waiting + chase + actioned + actionNote;
        }

        document.addEventListener('click', event => {
            const button = event.target.closest('.wip-actioned-btn');
            if (button) openActionedModal(button);
        });

        close.addEventListener('click', closeActionedModal);
        cancel.addEventListener('click', closeActionedModal);
        modal.addEventListener('click', event => {
            if (event.target === modal) closeActionedModal();
        });

        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (!actionLeadId || !waitingOn.value) return;

            save.disabled = true;
            save.textContent = 'Saving…';
            try {
                const response = await fetch('/lead/' + actionLeadId + '/wip-actioned', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        waiting_on: waitingOn.value,
                        next_chase_at: null,
                        action_note: null,
                    }),
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    const validation = data.errors ? Object.values(data.errors).flat().join(' ') : null;
                    throw new Error(validation || data.message || 'Could not update case');
                }

                const card = document.querySelector('.wip-lead-row[data-lead-id="' + actionLeadId + '"]');
                if (card) {
                    renderQueueState(card, data.queue_item);
                    card.dataset.queuePosition = String(data.queue_item.position || Number.MAX_SAFE_INTEGER);
                    card.dataset.lastActionedAt = data.queue_item.last_actioned_at || '';
                    const stack = card.closest('[data-stack-key]');
                    if (stack) stack.appendChild(card);
                }

                closeActionedModal();
                toast('Case moved to the bottom of this stack.');
                window.jinxWipRunTimeEngine?.();
            } catch (error) {
                alert(error.message);
            } finally {
                save.disabled = false;
                save.textContent = 'Move to bottom';
            }
        });
    })();

    const modal = document.getElementById('checklist-modal');
    const modalCaseName = document.getElementById('modal-case-name');
    const modalSubtitle = document.getElementById('modal-subtitle');
    const checklistItemsWrap = document.getElementById('checklist-items');
    const newItemNameInput = document.getElementById('new-item-name');
    const addItemBtn = document.getElementById('add-item-btn');
    const closeModalBtn = document.getElementById('close-modal-btn');

    let currentLeadId = null;
    let currentCaseName = '';

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text ?? '';
        return div.innerHTML;
    }

    function updateOutstandingPill(leadId, outstanding) {
        const pill = document.getElementById(`outstanding-pill-${leadId}`);
        if (!pill) return;

        pill.textContent = `${outstanding} outstanding`;
        pill.classList.remove('wip-chip-outstanding--clear', 'wip-chip-outstanding--pending');
        if (outstanding === 0) {
            pill.classList.add('wip-chip-outstanding--clear');
        } else {
            pill.classList.add('wip-chip-outstanding--pending');
        }
    }

    function updateStatusSelect(leadId, status) {
        const select = document.querySelector(`.status-select[data-lead-id="${leadId}"]`);
        if (select) {
            select.value = status;
            select.setAttribute('data-original', status);
        }
        const card = document.querySelector(`.wip-lead-row[data-lead-id="${leadId}"]`);
        if (card) {
            card.dataset.wipStatus = status;
            if (status !== 'Re-engaged') {
                card.dataset.reengagementUnseen = '0';
                card.classList.remove('wip-card-reengagement-unseen');
            }
        }
        applyWipFilters();
    }

    function renderChecklist(items, counts) {
        modalSubtitle.textContent = `${counts.outstanding} outstanding`;

        if (!items.length) {
            checklistItemsWrap.innerHTML = `
                <div style="padding:14px; border:1px dashed #374151; border-radius:12px; color:#9ca3af; text-align:center;">
                    No checklist items yet.
                </div>
            `;
            return;
        }

        checklistItemsWrap.innerHTML = items.map(item => `
            <div data-item-id="${item.id}" style="background:#0f172a; border:1px solid #374151; border-radius:12px; padding:12px;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" class="toggle-item" ${item.is_complete ? 'checked' : ''} style="width:18px; height:18px;">

                    <input
                        type="text"
                        class="item-name-input"
                        value="${escapeHtml(item.item_name)}"
                        style="flex:1; background:#111827; color:#fff; border:1px solid #374151; border-radius:8px; padding:10px; font-size:14px; text-decoration:${item.is_complete ? 'line-through' : 'none'};"
                    >

                    <button
                        type="button"
                        class="save-item-btn"
                        style="background:#2563eb; color:#fff; border:none; border-radius:8px; padding:10px 12px; font-size:13px; font-weight:700; cursor:pointer;">
                        Save
                    </button>

                    <button
                        type="button"
                        class="delete-item-btn"
                        style="background:#b91c1c; color:#fff; border:none; border-radius:8px; padding:10px 12px; font-size:13px; font-weight:700; cursor:pointer;">
                        Delete
                    </button>
                </div>
            </div>
        `).join('');
    }

    async function acknowledgeReengagementIfNeeded(leadId) {
        const card = document.querySelector('.wip-lead-row[data-lead-id="' + leadId + '"]');
        if (!card || card.dataset.reengagementUnseen !== '1') {
            return;
        }
        try {
            await fetch(wipReengagementAckUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ lead_id: parseInt(leadId, 10) }),
            });
            card.dataset.reengagementUnseen = '0';
            card.classList.remove('wip-card-reengagement-unseen');
            const badge = card.querySelector('.wip-card__badge--reengaged');
            if (badge) {
                badge.textContent = 'Re-engaged';
            }
        } catch (e) {}
    }

    async function loadChecklist(leadId, caseName) {
        await acknowledgeReengagementIfNeeded(leadId);

        currentLeadId = leadId;
        currentCaseName = caseName;
        modalCaseName.textContent = caseName;
        modalSubtitle.textContent = 'Loading...';
        checklistItemsWrap.innerHTML = '<div style="color:#9ca3af;">Loading...</div>';
        newItemNameInput.value = '';

        modal.style.display = 'block';

        const response = await fetch(`/lead/${leadId}/checklist`, {
            headers: {
                'Accept': 'application/json',
            }
        });

        const data = await response.json();
        renderChecklist(data.items, data.counts);
        updateOutstandingPill(leadId, data.counts.outstanding);
        updateStatusSelect(leadId, data.lead.wip_status);
    }

    async function refreshChecklist() {
        if (!currentLeadId) return;
        await loadChecklist(currentLeadId, currentCaseName);
    }

    document.addEventListener('click', async (event) => {
        const btn = event.target.closest('.open-checklist-btn');
        if (!btn) return;
        await loadChecklist(btn.dataset.leadId, btn.dataset.caseName);
    });

    closeModalBtn.addEventListener('click', () => {
        modal.style.display = 'none';
        currentLeadId = null;
        currentCaseName = '';
    });

    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.style.display = 'none';
            currentLeadId = null;
            currentCaseName = '';
        }
    });

    const sipModal = document.getElementById('wip-sip-modal');
    const sipForm = document.getElementById('wip-sip-form');
    const sipCaseName = document.getElementById('wip-sip-case-name');
    const sipInput = document.getElementById('wip-sip-booked-at');
    const sipSave = document.getElementById('wip-sip-save');
    const sipClose = document.getElementById('wip-sip-close');
    const sipCancel = document.getElementById('wip-sip-cancel');
    let sipModalLeadId = null;
    let sipModalSelect = null;
    let sipModalOriginalStatus = null;

    function toLocalDateTimeValue(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        if (!Number.isFinite(d.getTime())) return '';
        const pad = value => String(value).padStart(2, '0');
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function closeSipModal(restoreStatus) {
        if (restoreStatus && sipModalSelect && sipModalOriginalStatus !== null) {
            sipModalSelect.value = sipModalOriginalStatus;
        }
        sipModal.classList.remove('is-open');
        sipModal.setAttribute('aria-hidden', 'true');
        sipModalLeadId = null;
        sipModalSelect = null;
        sipModalOriginalStatus = null;
        sipInput.value = '';
    }

    function openSipModal(card, select) {
        sipModalLeadId = Number(card.dataset.leadId);
        sipModalSelect = select || card.querySelector('.status-select');
        sipModalOriginalStatus = sipModalSelect
            ? (sipModalSelect.getAttribute('data-original') || card.dataset.wipStatus || sipModalSelect.value)
            : (card.dataset.wipStatus || 'SIP Booked');
        const title = card.querySelector('.wip-card__title')?.innerText?.trim() || ('Lead #' + sipModalLeadId);
        sipCaseName.textContent = title + ' — booked SIP appointment';
        sipInput.value = toLocalDateTimeValue(card.dataset.sipAt || '');
        sipModal.classList.add('is-open');
        sipModal.setAttribute('aria-hidden', 'false');
        window.setTimeout(() => sipInput.focus(), 0);
    }

    async function saveWipStatus(leadId, newValue, deadReason, sipBookedAt) {
        const response = await fetch('/lead/' + leadId + '/wip-status', {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                wip_status: newValue,
                dead_reason: deadReason || null,
                sip_booked_at: sipBookedAt || null,
            })
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = data.errors ? Object.values(data.errors).flat().join(' ') : '';
            throw new Error(validation || data.message || 'Could not update status.');
        }
        updateStatusSelect(leadId, data.wip_status);
        return data;
    }

    document.addEventListener('change', async event => {
        const select = event.target.closest('.status-select');
        if (!select) return;

        const card = select.closest('.wip-lead-row');
        const leadId = select.dataset.leadId;
        const originalValue = select.getAttribute('data-original') || card?.dataset.wipStatus || select.value;
        const newValue = select.value;

        if (newValue === 'SIP Booked') {
            openSipModal(card, select);
            return;
        }

        let deadReason = null;
        if (newValue === 'Dead') {
            deadReason = prompt('Why is this case Dead?');
            if (!deadReason || !deadReason.trim()) {
                select.value = originalValue;
                return;
            }
        }

        select.disabled = true;
        try {
            await saveWipStatus(leadId, newValue, deadReason, null);
            await window.jinxWipSyncDashboard?.(true);
        } catch (error) {
            alert(error.message || 'Could not update status.');
            select.value = originalValue;
        } finally {
            select.disabled = false;
        }
    });

    document.addEventListener('click', async event => {
        const edit = event.target.closest('[data-sip-edit]');
        if (edit) {
            const card = edit.closest('.wip-lead-row');
            if (card) openSipModal(card, card.querySelector('.status-select'));
            return;
        }

        const done = event.target.closest('[data-sip-prep-done]');
        if (!done) return;

        const leadId = done.dataset.leadId;
        done.disabled = true;
        done.textContent = 'Saving…';
        try {
            const response = await fetch('/lead/' + leadId + '/sip-prep-complete', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) throw new Error(data.message || 'Could not mark prep complete.');
            window.jinxWipToast?.('SIP prep call marked complete.');
            await window.jinxWipSyncDashboard?.(true);
        } catch (error) {
            alert(error.message || 'Could not mark prep complete.');
            done.disabled = false;
            done.textContent = 'Prep done';
        }
    });

    sipClose.addEventListener('click', () => closeSipModal(true));
    sipCancel.addEventListener('click', () => closeSipModal(true));
    sipModal.addEventListener('click', event => {
        if (event.target === sipModal) closeSipModal(true);
    });

    sipForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (!sipModalLeadId || !sipInput.value) return;

        const date = new Date(sipInput.value);
        if (!Number.isFinite(date.getTime()) || date.getTime() <= Date.now()) {
            alert('Choose a future SIP appointment time.');
            return;
        }

        sipSave.disabled = true;
        sipSave.textContent = 'Saving…';
        try {
            await saveWipStatus(sipModalLeadId, 'SIP Booked', null, date.toISOString());
            closeSipModal(false);
            window.jinxWipToast?.('SIP booked — prep call protected 15 minutes before.');
            await window.jinxWipSyncDashboard?.(true);
        } catch (error) {
            alert(error.message || 'Could not save SIP appointment.');
        } finally {
            sipSave.disabled = false;
            sipSave.textContent = 'Save SIP time';
        }
    });

    document.querySelectorAll('.status-select').forEach(select => {
        select.setAttribute('data-original', select.value);
    });

    addItemBtn.addEventListener('click', async () => {
        const itemName = newItemNameInput.value.trim();

        if (!currentLeadId || !itemName) {
            return;
        }

        const response = await fetch(`/lead/${currentLeadId}/checklist`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                item_name: itemName
            })
        });

        if (!response.ok) {
            alert('Could not add item.');
            return;
        }

        const data = await response.json();
        newItemNameInput.value = '';
        updateOutstandingPill(currentLeadId, data.counts.outstanding);
        updateStatusSelect(currentLeadId, data.wip_status);
        await refreshChecklist();
    });

    checklistItemsWrap.addEventListener('click', async (e) => {
        const itemRow = e.target.closest('[data-item-id]');
        if (!itemRow || !currentLeadId) return;

        const itemId = itemRow.dataset.itemId;

        if (e.target.classList.contains('save-item-btn')) {
            const input = itemRow.querySelector('.item-name-input');
            const itemName = input.value.trim();

            if (!itemName) {
                alert('Item name cannot be empty.');
                return;
            }

            const response = await fetch(`/lead/${currentLeadId}/checklist/${itemId}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    item_name: itemName
                })
            });

            if (!response.ok) {
                alert('Could not save item.');
                return;
            }

            await refreshChecklist();
        }

        if (e.target.classList.contains('delete-item-btn')) {
            const response = await fetch(`/lead/${currentLeadId}/checklist/${itemId}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                }
            });

            if (!response.ok) {
                alert('Could not delete item.');
                return;
            }

            const data = await response.json();
            updateOutstandingPill(currentLeadId, data.counts.outstanding);
            updateStatusSelect(currentLeadId, data.wip_status);
            await refreshChecklist();
        }
    });

    checklistItemsWrap.addEventListener('change', async (e) => {
        const itemRow = e.target.closest('[data-item-id]');
        if (!itemRow || !currentLeadId) return;

        if (e.target.classList.contains('toggle-item')) {
            const itemId = itemRow.dataset.itemId;
            const checked = e.target.checked;

            const response = await fetch(`/lead/${currentLeadId}/checklist/${itemId}/toggle`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    is_complete: checked ? 1 : 0
                })
            });

            if (!response.ok) {
                alert('Could not update item.');
                return;
            }

            const data = await response.json();
            updateOutstandingPill(currentLeadId, data.counts.outstanding);
            updateStatusSelect(currentLeadId, data.wip_status);
            await refreshChecklist();
        }
    });

    (function () {
        const LIVE_SYNC_MS = 15000;
        const TIME_TICK_MS = 1000;
        const dashboard = document.getElementById('wip-dashboard-grid');
        const liveIndicator = document.getElementById('wip-live-indicator');
        const liveUpdated = document.getElementById('wip-live-updated');
        const refreshBtn = document.getElementById('wip-refresh-btn');
        const nextSipBanner = document.getElementById('wip-next-sip-banner');
        const alertedCallbacks = new Set(wipCallbackInitialDueIds);
        const alertedSipPrep = new Set();
        let syncInFlight = false;
        let lastSyncAt = Date.now();

        function isFormFieldFocused() {
            const el = document.activeElement;
            if (!el) return false;
            if (el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') return true;
            if (el.tagName !== 'INPUT') return false;
            const type = el.type || 'text';
            return !['button', 'submit', 'checkbox', 'radio'].includes(type);
        }

        function syncBlocked() {
            return document.getElementById('checklist-modal')?.style.display === 'block'
                || document.getElementById('wip-actioned-modal')?.classList.contains('is-open')
                || document.getElementById('wip-sip-modal')?.classList.contains('is-open')
                || !!document.querySelector('.is-dragging')
                || isFormFieldFocused();
        }

        function setLiveState(ok) {
            if (liveIndicator) {
                liveIndicator.classList.toggle('is-error', !ok);
                liveIndicator.childNodes[liveIndicator.childNodes.length - 1].textContent = ok ? ' LIVE' : ' OFFLINE';
            }
            if (ok) lastSyncAt = Date.now();
        }

        function updateLiveAge() {
            if (!liveUpdated) return;
            const seconds = Math.max(0, Math.floor((Date.now() - lastSyncAt) / 1000));
            liveUpdated.textContent = seconds < 5 ? 'Updated just now' : ('Updated ' + seconds + 's ago');
        }

        function queuePosition(card) {
            const n = Number(card.dataset.queuePosition || Number.MAX_SAFE_INTEGER);
            return Number.isFinite(n) ? n : Number.MAX_SAFE_INTEGER;
        }

        function millisUntil(raw) {
            if (!raw) return Number.POSITIVE_INFINITY;
            const time = new Date(raw).getTime();
            return Number.isFinite(time) ? time - Date.now() : Number.POSITIVE_INFINITY;
        }

        function humanCountdown(ms, dueWord) {
            if (!Number.isFinite(ms)) return '';
            const overdue = ms < 0;
            const totalSeconds = Math.max(0, Math.floor(Math.abs(ms) / 1000));
            const hours = Math.floor(totalSeconds / 3600);
            const mins = Math.floor((totalSeconds % 3600) / 60);
            const secs = totalSeconds % 60;
            let value = '';
            if (hours > 0) value = hours + 'h ' + mins + 'm';
            else if (mins > 0) value = mins + 'm ' + secs + 's';
            else value = secs + 's';
            return overdue ? ((dueWord || 'OVERDUE') + ' ' + value) : ('in ' + value);
        }

        function callbackRank(card) {
            if (window.jinxWipCallbackWasActioned?.(card)) return [20, queuePosition(card)];
            const ms = millisUntil(card.dataset.callbackAt);
            if (!Number.isFinite(ms)) return [20, queuePosition(card)];
            if (ms <= 0) return [0, new Date(card.dataset.callbackAt).getTime()];
            if (ms <= 15 * 60000) return [1, new Date(card.dataset.callbackAt).getTime()];
            if (ms <= 30 * 60000) return [2, new Date(card.dataset.callbackAt).getTime()];
            if (ms <= 60 * 60000) return [3, new Date(card.dataset.callbackAt).getTime()];
            return [20, queuePosition(card)];
        }

        function priorityRank(card) {
            if (card.dataset.wipStatus !== 'SIP Booked') return [20, queuePosition(card)];
            if (!card.dataset.sipAt) return [-1, queuePosition(card)];
            const sipTime = new Date(card.dataset.sipAt).getTime();
            if (!card.dataset.sipPrepCompletedAt) return [0, new Date(card.dataset.sipPrepAt).getTime()];
            return [10, sipTime];
        }

        function compareRanks(a, b, ranker) {
            const ar = ranker(a);
            const br = ranker(b);
            if (ar[0] !== br[0]) return ar[0] - br[0];
            if (ar[1] !== br[1]) return ar[1] - br[1];
            return Number(a.dataset.leadId) - Number(b.dataset.leadId);
        }

        function sortStack(stack, ranker) {
            if (!stack) return;
            const cards = Array.from(stack.querySelectorAll(':scope > .wip-lead-row'));
            const sorted = cards.slice().sort((a, b) => compareRanks(a, b, ranker));
            const changed = sorted.some((card, index) => card !== cards[index]);
            if (!changed) return;

            const rects = new Map(cards.map(card => [card.dataset.leadId, card.getBoundingClientRect()]));
            sorted.forEach(card => stack.appendChild(card));
            sorted.forEach(card => {
                const old = rects.get(card.dataset.leadId);
                const now = card.getBoundingClientRect();
                if (!old) return;
                const dx = old.left - now.left;
                const dy = old.top - now.top;
                if (!dx && !dy) return;
                card.style.transition = 'none';
                card.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
                requestAnimationFrame(() => {
                    card.style.transition = '';
                    card.style.transform = '';
                });
            });
        }

        function alertTimed(kind, card) {
            if (typeof window.jinxWipPlayReengagementAlertSound === 'function') {
                window.jinxWipPlayReengagementAlertSound();
                setTimeout(window.jinxWipPlayReengagementAlertSound, 420);
                setTimeout(window.jinxWipPlayReengagementAlertSound, 840);
            }
            if ('Notification' in window && Notification.permission === 'granted') {
                const name = card.querySelector('.wip-card__title')?.innerText?.trim() || 'Jinx case';
                new Notification(kind === 'sip' ? 'Jinx SIP prep call due' : 'Jinx callback due', {
                    body: name,
                });
            }
        }

        function updateCardTiming(card) {
            card.classList.remove(
                'wip-card-sip', 'wip-card-sip-upcoming', 'wip-card-sip-soon',
                'wip-card-sip-urgent', 'wip-card-sip-due', 'wip-card-sip-prepped',
                'is-callback-soon', 'is-callback-urgent', 'is-callback-due'
            );

            if (card.dataset.wipStatus === 'SIP Booked') {
                if (card.dataset.sipPrepCompletedAt) {
                    card.classList.add('wip-card-sip-prepped');
                } else if (card.dataset.sipAt && card.dataset.sipPrepAt) {
                    const ms = millisUntil(card.dataset.sipPrepAt);
                    if (ms <= 0) card.classList.add('wip-card-sip-due');
                    else if (ms <= 15 * 60000) card.classList.add('wip-card-sip-urgent');
                    else if (ms <= 30 * 60000) card.classList.add('wip-card-sip-soon');
                    else if (ms <= 60 * 60000) card.classList.add('wip-card-sip-upcoming');
                    else card.classList.add('wip-card-sip');

                    const countdown = card.querySelector('[data-sip-countdown]');
                    if (countdown) countdown.textContent = '· ' + humanCountdown(ms, 'OVERDUE');

                    const id = Number(card.dataset.leadId);
                    if (ms <= 0 && !alertedSipPrep.has(id)) {
                        alertedSipPrep.add(id);
                        alertTimed('sip', card);
                    }
                }
            }

            if (card.dataset.callbackAt) {
                const countdown = card.querySelector('[data-callback-countdown]');
                if (window.jinxWipCallbackWasActioned?.(card)) {
                    if (countdown) countdown.textContent = 'actioned';
                } else {
                    const ms = millisUntil(card.dataset.callbackAt);
                    if (ms <= 0) card.classList.add('is-callback-due');
                    else if (ms <= 15 * 60000) card.classList.add('is-callback-urgent');
                    else if (ms <= 60 * 60000) card.classList.add('is-callback-soon');

                    if (countdown) countdown.textContent = humanCountdown(ms, 'OVERDUE');

                    const callbackId = Number(card.dataset.callbackId);
                    if (callbackId && ms <= 0 && !alertedCallbacks.has(callbackId)) {
                        alertedCallbacks.add(callbackId);
                        alertTimed('callback', card);
                    }
                }
            }
        }

        function updateNextSipBanner() {
            if (!nextSipBanner) return;
            const candidates = Array.from(document.querySelectorAll('#wip-priority-stack .wip-lead-row[data-wip-status="SIP Booked"]'))
                .filter(card => card.dataset.sipPrepAt && !card.dataset.sipPrepCompletedAt)
                .sort((a, b) => new Date(a.dataset.sipPrepAt).getTime() - new Date(b.dataset.sipPrepAt).getTime());

            const card = candidates[0];
            if (!card) {
                nextSipBanner.style.display = 'none';
                return;
            }

            const prep = new Date(card.dataset.sipPrepAt);
            const sip = new Date(card.dataset.sipAt);
            const ms = prep.getTime() - Date.now();
            const name = card.querySelector('.wip-card__title')?.innerText?.trim() || ('Lead #' + card.dataset.leadId);
            const time = d => d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });

            nextSipBanner.style.display = 'grid';
            nextSipBanner.classList.toggle('is-due', ms <= 0);
            nextSipBanner.querySelector('[data-next-sip-name]').textContent = name;
            nextSipBanner.querySelector('[data-next-sip-times]').textContent = 'Prep ' + time(prep) + ' · SIP ' + time(sip);
            nextSipBanner.querySelector('[data-next-sip-countdown]').textContent = humanCountdown(ms, 'OVERDUE');
            nextSipBanner.querySelector('[data-next-sip-open]').href = '/lead/' + card.dataset.leadId;
        }

        function updateLaneCounts() {
            document.querySelectorAll('[data-wip-lane]').forEach(lane => {
                const key = lane.dataset.wipLane;
                const count = lane.querySelectorAll('.wip-lead-row').length;
                const badge = lane.querySelector('[data-lane-count="' + key + '"]');
                if (badge) badge.textContent = count;
                const stack = lane.querySelector('[data-stack-key]');
                const empty = stack?.querySelector('[data-lane-empty]');
                if (empty) empty.style.display = count ? 'none' : '';
            });
        }

        function runTimeEngine() {
            document.querySelectorAll('.wip-lead-row').forEach(updateCardTiming);
            sortStack(document.getElementById('wip-priority-stack'), priorityRank);
            sortStack(document.getElementById('wip-active-stack'), callbackRank);
            updateNextSipBanner();
            updateLaneCounts();
        }
        window.jinxWipRunTimeEngine = runTimeEngine;

        function captureRects() {
            return new Map(Array.from(document.querySelectorAll('#wip-dashboard-grid .wip-lead-row')).map(card => [
                card.dataset.leadId,
                card.getBoundingClientRect(),
            ]));
        }

        function animateFromRects(oldRects) {
            requestAnimationFrame(() => {
                document.querySelectorAll('#wip-dashboard-grid .wip-lead-row').forEach(card => {
                    const old = oldRects.get(card.dataset.leadId);
                    if (!old) return;
                    const now = card.getBoundingClientRect();
                    const dx = old.left - now.left;
                    const dy = old.top - now.top;
                    if (!dx && !dy) return;
                    card.style.transition = 'none';
                    card.style.transform = 'translate(' + dx + 'px,' + dy + 'px)';
                    requestAnimationFrame(() => {
                        card.style.transition = '';
                        card.style.transform = '';
                    });
                });
            });
        }

        function runNewLeadAlerts() {
            const key = 'jinx_wip_live_seen_leads';
            let seen = [];
            try { seen = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) {}
            const seenSet = new Set(Array.isArray(seen) ? seen.map(Number) : []);
            const cards = Array.from(document.querySelectorAll('.wip-card-undialled-attention[data-lead-id]'));
            const fresh = cards.filter(card => !seenSet.has(Number(card.dataset.leadId)));
            cards.forEach(card => seenSet.add(Number(card.dataset.leadId)));

            if (fresh.length && seen.length) {
                window.jinxWipPlayReengagementAlertSound?.();
                const name = fresh[fresh.length - 1].querySelector('.wip-card__title')?.innerText?.trim() || 'New lead';
                window.jinxWipToast?.(fresh.length === 1 ? ('New lead: ' + name) : (fresh.length + ' new leads — latest: ' + name));
            }
            try { localStorage.setItem(key, JSON.stringify(Array.from(seenSet).slice(-500))); } catch (e) {}
        }

        async function syncDashboard(force) {
            if (syncInFlight || (!force && syncBlocked())) return;
            syncInFlight = true;
            refreshBtn?.classList.add('is-spinning');

            try {
                const response = await fetch(window.location.href, {
                    headers: {
                        'Accept': 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Cache-Control': 'no-cache',
                    },
                    cache: 'no-store',
                });
                if (!response.ok) throw new Error('Refresh failed');
                const html = await response.text();
                const parsed = new DOMParser().parseFromString(html, 'text/html');
                const nextDashboard = parsed.getElementById('wip-dashboard-grid');
                if (!nextDashboard) throw new Error('Dashboard response missing');

                const oldRects = captureRects();
                dashboard.innerHTML = nextDashboard.innerHTML;

                const currentAttention = document.getElementById('wip-attention-required-section');
                const nextAttention = parsed.getElementById('wip-attention-required-section');
                if (currentAttention && nextAttention) {
                    currentAttention.innerHTML = nextAttention.innerHTML;
                    currentAttention.className = nextAttention.className;
                    currentAttention.style.display = nextAttention.style.display;
                }

                runTimeEngine();
                applyWipFilters();
                animateFromRects(oldRects);
                runNewLeadAlerts();
                setLiveState(true);
            } catch (error) {
                setLiveState(false);
            } finally {
                refreshBtn?.classList.remove('is-spinning');
                syncInFlight = false;
            }
        }
        window.jinxWipSyncDashboard = syncDashboard;

        refreshBtn?.addEventListener('click', () => syncDashboard(true));

        if ('Notification' in window && Notification.permission === 'default') {
            document.addEventListener('click', () => Notification.requestPermission().catch(() => {}), { once: true });
        }

        runTimeEngine();
        runNewLeadAlerts();
        setInterval(runTimeEngine, TIME_TICK_MS);
        setInterval(() => syncDashboard(false), LIVE_SYNC_MS);
        setInterval(updateLiveAge, 1000);
    })();


    (function () {
        const POLL_MS = 12000;
        const alertedReengagementIds = new Set();
        const toast = document.getElementById('wip-reengagement-toast');

        async function pollReengagement() {
            try {
                const r = await fetch(wipReengagementPollUrl, {
                    headers: { 'Accept': 'application/json' },
                });
                if (!r.ok) {
                    return;
                }
                const data = await r.json();
                const events = data.events || [];
                events.forEach(function (ev) {
                    const id = ev.id;
                    if (wipReengagementSnapshotIds.has(id) || alertedReengagementIds.has(id)) {
                        return;
                    }
                    alertedReengagementIds.add(id);
                    if (toast) {
                        toast.style.display = 'block';
                        const name = ev.lead_name || ('Lead #' + ev.lead_id);
                        toast.textContent = '🔥 Re-engagement: ' + name + ' — check WIP.';
                        setTimeout(function () {
                            toast.style.display = 'none';
                        }, 10000);
                    }
                    if (typeof window.jinxWipPlayReengagementAlertSound === 'function') {
                        window.jinxWipPlayReengagementAlertSound();
                    }
                });
            } catch (e) {}
        }

        setInterval(pollReengagement, POLL_MS);
    })();
</script>

</body>
</html>
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

        .wip-callback-when{font-size:13px;font-weight:800;color:#bfdbfe;margin-top:7px}.wip-callback-reason{margin-top:8px;padding:9px 10px;border-radius:8px;background:rgba(15,23,42,.7);color:#cbd5e1;font-size:12px;line-height:1.4;min-height:34px}.wip-callback-reason span{display:block;color:#64748b;text-transform:uppercase;font-size:9px;font-weight:800;letter-spacing:.05em;margin-bottom:3px}.wip-callback-actions{margin-top:8px}.wip-callback-actions a{display:inline-block;color:#93c5fd;font-size:11px;font-weight:800;text-decoration:none}.wip-callback-actions a:hover{color:#dbeafe}
        .wip-workdesk{display:block;margin-bottom:18px}.wip-callback-column{min-width:0}.wip-callback-column #wip-callback-list.wip-attention-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.wip-callback-column .wip-attention-section{margin:0}.wip-callback-column #wip-callback-section{box-sizing:border-box;padding:0;overflow:hidden}.wip-callback-column .wip-attention-header{padding:12px 14px 10px;margin:0;border-bottom:1px solid rgba(51,65,85,.65);background:rgba(15,23,42,.45)}.wip-callback-column #wip-callback-list{max-height:260px;overflow-y:auto;overscroll-behavior:contain;padding:9px;scrollbar-width:thin;scrollbar-color:#475569 transparent}.wip-callback-column #wip-callback-list .wip-card{margin-bottom:8px;border-radius:10px;background:rgba(15,23,42,.72)}
        .wip-desktop-layout{display:block}.wip-main-column{min-width:0}.wip-queue-heading{display:flex;align-items:end;justify-content:space-between;gap:12px;border-top:1px solid rgba(51,65,85,.7);padding-top:18px;margin:22px 0 10px}.wip-queue-heading h2{margin:0;font-size:22px;letter-spacing:-.025em;color:#f8fafc}.wip-queue-heading p{font-size:12px;margin:4px 0 0;color:#64748b}.wip-queue-count{font-size:11px;color:#94a3b8;border:1px solid #334155;background:#111827;padding:5px 9px;border-radius:999px}
        .wip-filter-bar{padding:10px;border:1px solid rgba(51,65,85,.55);border-radius:10px;background:rgba(15,23,42,.45)}
        #wip-leads-grid{grid-template-columns:1fr;gap:8px!important}.wip-card{transition:border-color .15s ease,transform .15s ease,background .15s ease}.wip-card:hover{border-color:#475569;background:#111b2d}
        .wip-lead-row{position:relative;padding:10px 12px 10px 44px;gap:7px;border-radius:8px}.wip-lead-row.is-dragging{opacity:.42;border-style:dashed}.wip-lead-row.is-drop-target{border-color:#60a5fa}.wip-stack-handle{position:absolute;left:8px;top:10px;width:26px;height:34px;border:1px solid #334155;border-radius:6px;background:#0b1220;color:#64748b;font-size:20px;line-height:1;cursor:grab;display:flex;align-items:center;justify-content:center;user-select:none}.wip-stack-handle:active{cursor:grabbing}.wip-lead-row .wip-card__title a{font-size:15px}.wip-lead-row .wip-card__meta{padding-top:3px}.wip-lead-row .wip-card__controls{gap:7px}.wip-lead-row .wip-card__controls .status-select{flex:0 1 240px;min-width:180px;min-height:32px;padding:5px 8px;font-size:12px}
        .wip-stack-state{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:11px;color:#94a3b8}.wip-stack-state__pill{display:inline-flex;align-items:center;gap:4px;padding:3px 7px;border-radius:999px;border:1px solid #334155;background:#0b1220;color:#cbd5e1}.wip-stack-state__pill.is-due{border-color:#b45309;background:#451a03;color:#fde68a}.wip-stack-state__note{color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:560px}
        .wip-actioned-btn{margin-left:auto;min-height:32px;border:1px solid #2563eb;border-radius:7px;background:#172554;color:#bfdbfe;padding:6px 10px;font-size:11px;font-weight:800;cursor:pointer}.wip-actioned-btn:hover{background:#1e3a8a;border-color:#60a5fa}
        .wip-action-modal{display:none;position:fixed;inset:0;z-index:12100;background:rgba(2,6,23,.68);padding:18px;box-sizing:border-box}.wip-action-modal.is-open{display:block}.wip-action-modal__card{max-width:520px;margin:8vh auto 0;background:#111827;border:1px solid #374151;border-radius:14px;box-shadow:0 22px 60px rgba(0,0,0,.55);overflow:hidden}.wip-action-modal__head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:14px 16px;border-bottom:1px solid #334155}.wip-action-modal__title{font-size:17px;font-weight:800;color:#f8fafc}.wip-action-modal__sub{margin-top:3px;color:#64748b;font-size:11px}.wip-action-modal__close{width:32px;height:32px;border:1px solid #334155;border-radius:7px;background:#0f172a;color:#cbd5e1;cursor:pointer}.wip-action-modal__body{padding:15px}.wip-action-modal__field{margin-bottom:13px}.wip-action-modal__field label{display:block;margin-bottom:5px;color:#94a3b8;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.wip-action-modal__field select,.wip-action-modal__field input,.wip-action-modal__field textarea{width:100%;box-sizing:border-box;border:1px solid #334155;border-radius:8px;background:#0b1220;color:#f8fafc;padding:9px 10px;font:inherit;font-size:13px}.wip-action-modal__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.wip-action-modal__cancel,.wip-action-modal__save{border-radius:8px;padding:8px 12px;font-size:12px;font-weight:800;cursor:pointer}.wip-action-modal__cancel{border:1px solid #334155;background:#111827;color:#cbd5e1}.wip-action-modal__save{border:1px solid #2563eb;background:#2563eb;color:#fff}
        @media(max-width:900px){.wip-page{padding-left:16px!important;padding-right:16px!important}.wip-callback-column #wip-callback-list.wip-attention-grid{grid-template-columns:1fr}.wip-lead-row{padding-left:42px}.wip-actioned-btn{margin-left:0}}
    </style>
</head>
<body style="margin:0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#0a0f1a; color:#f9fafb; min-height:100vh;">

<div class="wip-page" style="max-width:1680px; margin:0 auto; padding:18px 28px 28px; box-sizing:border-box;">

    @include('partials.app-nav')

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
    <div class="wip-workdesk">
      <div class="wip-callback-column">
    <section id="wip-callback-section" class="wip-attention-section {{ collect($scheduledCallbacks)->contains('due', true) ? 'wip-attention-section--active' : '' }}" style="border-color:rgba(59,130,246,.45); background:linear-gradient(180deg,rgba(30,58,138,.22) 0%,rgba(15,23,42,.65) 100%);">
        <div class="wip-attention-header">
            <h2 class="wip-attention-title">📞 Scheduled callbacks</h2>
            <span class="wip-attention-count" id="wip-callback-count" style="border-color:rgba(96,165,250,.5);background:rgba(30,64,175,.4);">{{ count($scheduledCallbacks) }}</span>
        </div>
        <div id="wip-callback-list" class="wip-attention-grid">
            @forelse($scheduledCallbacks as $callback)
                <article class="wip-card {{ $callback['overdue'] ? 'wip-card-attention' : '' }}" data-callback-id="{{ $callback['callback_id'] }}">
                    <div class="wip-card__row1"><div class="wip-card__title"><a href="{{ url('/lead/'.$callback['lead_id']) }}">{{ $callback['lead_name'] }}</a></div><div class="wip-card-actions"><span class="wip-chip {{ $callback['due'] ? 'wip-channel-chip' : '' }}">{{ $callback['due'] ? ($callback['overdue'] ? 'OVERDUE' : 'DUE NOW') : $callback['relative_due'] }}</span></div></div>
                    <div class="wip-callback-when">{{ $callback['callback_full_display'] }}</div>
                    <div class="wip-callback-reason"><span>Callback notes</span>{{ $callback['comments'] !== '' ? $callback['comments'] : 'No callback notes were recorded.' }}</div>
                    @if(!empty($callback['creditor_contact']))<div class="wip-callback-reason"><span>Creditor contact</span><strong>{{ $callback['creditor_contact']['name'] }}</strong> — {{ $callback['creditor_contact']['phone'] }}@if(!empty($callback['creditor_contact']['opening_hours']))<br>{{ $callback['creditor_contact']['opening_hours'] }}@endif @if(!empty($callback['creditor_contact']['notes']))<br>{{ $callback['creditor_contact']['notes'] }}@endif</div>@endif
                    <div class="wip-callback-actions"><a href="{{ url('/lead/'.$callback['lead_id']) }}">Open case &amp; callback brief</a></div>
                </article>
            @empty
                <div class="wip-attention-empty" id="wip-callback-empty">No scheduled callbacks.</div>
            @endforelse
        </div>
    </section>
      </div>
    </div>

    <section aria-labelledby="wip-attention-required-heading" class="wip-attention-section {{ $attentionCount > 0 ? 'wip-attention-section--active' : '' }}">
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

    <div class="wip-queue-heading"><div><h2>Working stack</h2><p>Work from the top. Drag cases to reorder them; after actioning a case, record what you are waiting on and it moves to the bottom.</p></div><span class="wip-queue-count">{{ count($leads) }} active</span></div>
    <div id="wip-filter-bar" class="wip-filter-bar">
        <input
            type="search"
            id="wip-filter-name"
            autocomplete="off"
            placeholder="Filter by name…"
            aria-label="Filter leads by name"
        >
        <select
            id="wip-filter-status"
            aria-label="Filter by status"
        >
            <option value="">All statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status }}">{{ $status }}</option>
            @endforeach
        </select>
        <select
            id="wip-filter-source"
            aria-label="Filter by source"
        >
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

    <div id="wip-leads-grid" style="display:grid; gap:10px;">
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
        @endphp
        @forelse($leads as $lead)
            @php
                $caseName = trim(($lead->first_name ?? '') . ' ' . ($lead->last_name ?? ''));
                if ($caseName === '') {
                    $caseName = 'Lead #' . $lead->id;
                }

                $outstanding = (int) ($lead->checklist_outstanding_count ?? 0);
                $sourceLabel = \App\Support\LeadSourceDisplay::label($lead->source);
                $sourceRaw = $lead->source === null ? '' : trim((string) $lead->source);
                $lastDialled = $lead->last_dialled_at;
                $lastDialledText = $lastDialled ? $lastDialled->format('d M Y, H:i') : 'Never dialled';
                $createdText = $lead->created_at
                    ? $lead->created_at->format('j M Y, H:i')
                    : '—';
                $canCall = (bool) trim((string) ($lead->phone_number ?? ''));
                $needsImmediateAttention = $lead->needsImmediateAttention();
                $isReengaged = $lead->wip_status === \App\Models\Lead::WIP_STATUS_REENGAGED;
                $reengagementUnseen = $isReengaged && isset($unseenReengagementLeadSet[(int) $lead->id]);
                $reengagementChannelLabel = $isReengaged
                    ? $formatReengagementChannel($reengagementChannelByLeadId[(int) $lead->id] ?? null)
                    : '';
                if ($reengagementUnseen) {
                    $cardAttentionClass = 'wip-card-reengagement-unseen';
                } elseif ($needsImmediateAttention) {
                    $cardAttentionClass = 'wip-card-undialled-attention';
                } elseif ($lead->isPriorityWip()) {
                    $cardAttentionClass = 'wip-card-priority';
                } else {
                    $cardAttentionClass = '';
                }
                $reengagementSeenCalm = $isReengaged && ! $reengagementUnseen;
                $queueItem = $lead->wipQueueItem;
                $waitingOnLabel = $lead->wip_waiting_on_label ?? null;
                $nextChaseAt = $queueItem?->next_chase_at;
                $lastActionedAt = $queueItem?->last_actioned_at;
                $actionNote = trim((string) ($queueItem?->action_note ?? ''));
                $chaseDue = (bool) ($lead->wip_chase_due ?? false);
            @endphp

            <div
                class="wip-card wip-lead-row {{ $cardAttentionClass }}{{ $reengagementSeenCalm ? ' wip-card-reengagement-seen' : '' }}"
                data-lead-id="{{ $lead->id }}"
                data-lead-name="{{ strtolower($caseName) }}"
                data-wip-status="{{ $lead->wip_status }}"
                data-lead-source="{{ e($sourceRaw) }}"
                data-reengagement-unseen="{{ $reengagementUnseen ? '1' : '0' }}"
                data-queue-position="{{ (int) ($lead->wip_queue_position ?? 0) }}"
            >
                <button type="button" class="wip-stack-handle" draggable="true" aria-label="Drag {{ $caseName }} to reorder" title="Drag to reorder">≡</button>
                <div class="wip-card__row1">
                    <div class="wip-card__title">
                        <a href="{{ url('/lead/' . $lead->id) }}">{{ $caseName }}</a>
                    </div>
                    <div class="wip-card-actions">
                        @if ($isReengaged)
                            <span class="wip-chip wip-card__badge--reengaged" title="Re-engagement — open checklist to acknowledge">{{ $reengagementUnseen ? 'Re-engaged · review' : 'Re-engaged' }}</span>
                            @if ($reengagementChannelLabel !== '')
                                <span class="wip-chip wip-card__badge--reengagement-channel" title="Channel">{{ $reengagementChannelLabel }}</span>
                            @endif
                        @endif
                        @if ($needsImmediateAttention)
                            <span class="wip-chip wip-card__badge--undialled" title="Priority intake, never dialled, created within {{ \App\Models\Lead::IMMEDIATE_ATTENTION_FRESH_HOURS }}h">New undialled</span>
                        @endif
                        <button
                            type="button"
                            id="outstanding-pill-{{ $lead->id }}"
                            class="open-checklist-btn wip-chip wip-chip-outstanding {{ $outstanding === 0 ? 'wip-chip-outstanding--clear' : 'wip-chip-outstanding--pending' }}"
                            data-lead-id="{{ $lead->id }}"
                            data-case-name="{{ $caseName }}"
                            title="Open checklist"
                        >{{ $outstanding }} outstanding</button>
                        @if ($canCall)
                            @include('partials.lead-click-to-call', ['lead' => $lead, 'variant' => 'icon'])
                        @endif
                    </div>
                </div>

                <div class="wip-card__meta">
                    <span class="wip-meta-k">Created</span> <span class="wip-meta-v">{{ $createdText }}</span>
                    <span class="wip-meta-dot" aria-hidden="true">·</span>
                    <span class="wip-meta-k">Last dialled</span> <span class="wip-meta-v">{{ $lastDialledText }}</span>
                    <span class="wip-meta-dot" aria-hidden="true">·</span>
                    <span class="wip-meta-pill">{{ $sourceLabel }}</span>
                </div>

                <div class="wip-stack-state" data-stack-state>
                    @if ($waitingOnLabel)
                        <span class="wip-stack-state__pill">Waiting on: <strong>{{ $waitingOnLabel }}</strong></span>
                    @else
                        <span class="wip-stack-state__pill">Not actioned yet</span>
                    @endif
                    @if ($nextChaseAt)
                        <span class="wip-stack-state__pill {{ $chaseDue ? 'is-due' : '' }}" data-chase-pill>{{ $chaseDue ? 'Chase due' : 'Chase' }}: {{ $nextChaseAt->format('D j M, H:i') }}</span>
                    @endif
                    @if ($lastActionedAt)
                        <span class="wip-stack-state__pill">Last actioned {{ $lastActionedAt->diffForHumans() }}</span>
                    @endif
                    @if ($actionNote !== '')
                        <span class="wip-stack-state__note" title="{{ $actionNote }}">{{ $actionNote }}</span>
                    @endif
                </div>

                <div class="wip-card__controls">
                    <select
                        data-lead-id="{{ $lead->id }}"
                        class="status-select">
                        @foreach($statuses as $status)
                            <option value="{{ $status }}" {{ $lead->wip_status === $status ? 'selected' : '' }}>
                                {{ $status }}
                            </option>
                        @endforeach
                    </select>
                    <button type="button" class="wip-actioned-btn" data-actioned-lead-id="{{ $lead->id }}" data-actioned-case-name="{{ $caseName }}">Actioned ↓</button>
                </div>
            </div>
        @empty
            <div style="background:#111827; border:1px solid #374151; border-radius:16px; padding:18px; color:#9ca3af;">
                No cases found.
            </div>
        @endforelse
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
            <div class="wip-action-modal__field">
                <label for="wip-actioned-chase-preset">Chase again</label>
                <select id="wip-actioned-chase-preset">
                    <option value="">No fixed date</option>
                    <option value="tomorrow">Tomorrow</option>
                    <option value="2d">In 2 days</option>
                    <option value="3d">In 3 days</option>
                    <option value="1w">In 1 week</option>
                    <option value="custom">Choose date/time…</option>
                </select>
            </div>
            <div class="wip-action-modal__field" id="wip-actioned-custom-wrap" style="display:none;">
                <label for="wip-actioned-custom">Custom chase time</label>
                <input type="datetime-local" id="wip-actioned-custom">
            </div>
            <div class="wip-action-modal__field">
                <label for="wip-actioned-note">Action note <span style="text-transform:none;font-weight:400;">(optional)</span></label>
                <textarea id="wip-actioned-note" rows="3" placeholder="e.g. Requested latest bank statements"></textarea>
            </div>
            <div class="wip-action-modal__actions">
                <button type="button" id="wip-actioned-cancel" class="wip-action-modal__cancel">Cancel</button>
                <button type="submit" id="wip-actioned-save" class="wip-action-modal__save">Move to bottom</button>
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
    const wipOpsAlertLeads = @json($ops_alert_leads ?? []);
    const wipReengagementSnapshotIds = new Set(@json($unseen_reengagement_event_ids ?? []));
    const wipReengagementPollUrl = @json(route('wip.reengagement-poll'));
    const wipReengagementAckUrl = @json(route('wip.reengagement-acknowledge'));
    const wipCallbackPollUrl = @json(route('wip.callback-poll'));
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

    document.querySelectorAll('.open-checklist-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            await loadChecklist(btn.dataset.leadId, btn.dataset.caseName);
        });
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

    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('change', async () => {
            const leadId = select.dataset.leadId;
            const originalValue = select.getAttribute('data-original') || select.value;
            const newValue = select.value;
            let deadReason = null;
            if (newValue === 'Dead') { deadReason = prompt('Why is this case Dead?'); if (!deadReason || !deadReason.trim()) { select.value = originalValue; return; } }

            try {
                const response = await fetch(`/lead/${leadId}/wip-status`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        wip_status: newValue,
                        dead_reason: deadReason
                    })
                });

                if (!response.ok) {
                    throw new Error('Failed to update status');
                }

                const data = await response.json();
                updateStatusSelect(leadId, data.wip_status);
            } catch (error) {
                alert('Could not update status.');
                select.value = originalValue;
            }
        });

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
        const refreshBtn = document.getElementById('wip-refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                refreshBtn.classList.add('is-spinning');
                window.location.reload();
            });
        }

        const IDLE_MS = 60000;
        const CHECK_MS = 5000;
        let lastActivity = Date.now();

        function markActive() {
            lastActivity = Date.now();
        }

        ['click', 'keydown', 'scroll', 'touchstart', 'focusin'].forEach(function (ev) {
            window.addEventListener(ev, markActive, { passive: true });
        });

        function isFormFieldFocused() {
            const el = document.activeElement;
            if (!el) return false;
            const tag = el.tagName;
            if (tag === 'TEXTAREA' || tag === 'SELECT') return true;
            if (tag === 'INPUT') {
                const t = el.type || 'text';
                if (t === 'button' || t === 'submit' || t === 'checkbox' || t === 'radio') return false;
                return true;
            }
            return false;
        }

        setInterval(function () {
            if (document.getElementById('checklist-modal') && document.getElementById('checklist-modal').style.display === 'block') {
                return;
            }
            if (isFormFieldFocused()) return;
            if (Date.now() - lastActivity < IDLE_MS) return;
            window.location.reload();
        }, CHECK_MS);

        const BASELINE_KEY = 'jinx_wip_ops_baseline_done';
        const MAX_SEEN_KEY = 'jinx_wip_max_seen_lead_id';

        function playSoftBeep() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                const ctx = new Ctx();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.type = 'sine';
                o.frequency.value = 880;
                g.gain.value = 0.035;
                o.connect(g);
                g.connect(ctx.destination);
                o.start();
                setTimeout(function () {
                    o.stop();
                    ctx.close();
                }, 100);
            } catch (e) {}
        }

        function runOpsAlerts() {
            if (!wipOpsAlertLeads || !wipOpsAlertLeads.length) return;

            const ids = wipOpsAlertLeads.map(function (r) { return r.id; });
            const currentMax = ids.length ? Math.max.apply(null, ids) : 0;

            try {
                if (!localStorage.getItem(BASELINE_KEY)) {
                    localStorage.setItem(MAX_SEEN_KEY, String(currentMax));
                    localStorage.setItem(BASELINE_KEY, '1');
                    return;
                }
            } catch (e) {
                return;
            }

            let storedMax = 0;
            try {
                storedMax = parseInt(localStorage.getItem(MAX_SEEN_KEY) || '0', 10) || 0;
            } catch (e) {}

            const fresh = wipOpsAlertLeads.filter(function (r) {
                return r.eligible && r.id > storedMax;
            });

            if (fresh.length) {
                const toast = document.getElementById('wip-ops-toast');
                if (toast) {
                    toast.style.display = 'block';
                    toast.textContent = fresh.length === 1
                        ? ('New lead: ' + (fresh[0].label || ('#' + fresh[0].id)))
                        : (fresh.length + ' new leads — latest: ' + (fresh[fresh.length - 1].label || ('#' + fresh[fresh.length - 1].id)));
                    setTimeout(function () {
                        toast.style.display = 'none';
                    }, 8000);
                }
                playSoftBeep();
            }

            try {
                localStorage.setItem(MAX_SEEN_KEY, String(Math.max(storedMax, currentMax)));
            } catch (e) {}
        }

        runOpsAlerts();
    })();

    (function () {
        const alerted = new Set(wipCallbackInitialDueIds);
        const list = document.getElementById('wip-callback-list');
        const count = document.getElementById('wip-callback-count');
        const section = document.getElementById('wip-callback-section');
        const esc = (v) => String(v ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
        function render(items) {
            if (count) count.textContent = items.length;
            if (!list) return;
            if (!items.length) { list.innerHTML='<div class="wip-attention-empty">No scheduled callbacks.</div>'; if(section)section.classList.remove('wip-attention-section--active'); return; }
            if(section)section.classList.toggle('wip-attention-section--active',items.some(x=>x.due));
            list.innerHTML=items.map(x=>`<article class="wip-card ${x.overdue?'wip-card-attention':''}" data-callback-id="${x.callback_id}"><div class="wip-card__row1"><div class="wip-card__title"><a href="/lead/${x.lead_id}">${esc(x.lead_name)}</a></div><div class="wip-card-actions"><span class="wip-chip ${x.due?'wip-channel-chip':''}">${x.due?(x.overdue?'OVERDUE':'DUE NOW'):esc(x.relative_due)}</span></div></div><div class="wip-callback-when">${esc(x.callback_full_display)}</div><div class="wip-callback-reason"><span>Callback notes</span>${esc(x.comments||'No callback notes were recorded.')}</div>${x.creditor_contact?`<div class="wip-callback-reason"><span>Creditor contact</span><strong>${esc(x.creditor_contact.name)}</strong> — ${esc(x.creditor_contact.phone||'')}${x.creditor_contact.opening_hours?'<br>'+esc(x.creditor_contact.opening_hours):''}${x.creditor_contact.notes?'<br>'+esc(x.creditor_contact.notes):''}</div>`:''}<div class="wip-callback-actions"><a href="/lead/${x.lead_id}">Open case &amp; callback brief</a></div></article>`).join('');
        }
        async function poll() {
            try {
                const r=await fetch(wipCallbackPollUrl,{headers:{'Accept':'application/json'}}); if(!r.ok)return; const data=await r.json(); const items=data.callbacks||[]; render(items);
                items.filter(x=>x.due).forEach(x=>{ if(alerted.has(x.callback_id))return; alerted.add(x.callback_id); if(typeof window.jinxWipPlayReengagementAlertSound==='function'){ window.jinxWipPlayReengagementAlertSound(); setTimeout(window.jinxWipPlayReengagementAlertSound,450); setTimeout(window.jinxWipPlayReengagementAlertSound,900); } if('Notification' in window && Notification.permission==='granted') new Notification('Jinx callback due',{body:x.lead_name+(x.comments?' — '+x.comments:'')}); });
            } catch(e) {}
        }
        if ('Notification' in window && Notification.permission === 'default') document.addEventListener('click',()=>Notification.requestPermission().catch(()=>{}),{once:true});
        setInterval(poll,10000);
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
@php
    $awaitingType = (string) ($job->awaiting_input_type ?? '');
    $awaitingPayload = is_array($job->awaiting_input_payload) ? $job->awaiting_input_payload : [];
    $options = isset($awaitingPayload['options']) && is_array($awaitingPayload['options']) ? $awaitingPayload['options'] : [];
@endphp

@if ($job->status === 'awaiting_input')
    <h2 style="font-size:16px; margin:14px 0 8px;">Provide Input</h2>

    @if (session('status'))
        <div style="margin-bottom:8px;">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div style="margin-bottom:8px;">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('local-worker.jobs.provide-input', $job) }}">
        @csrf

        @if ($awaitingType === 'identity_questions')
            <div style="margin-bottom:8px;">
                <strong>{{ $awaitingPayload['question'] ?? 'Select an option:' }}</strong>
            </div>

            @forelse ($options as $option)
                <label style="display:block; margin-bottom:6px;">
                    <input type="radio" name="selected_option" value="{{ $option }}" required>
                    {{ $option }}
                </label>
            @empty
                <div style="margin-bottom:8px;">No options provided in payload.</div>
                <input type="text" name="selected_option" placeholder="Enter selected option" required>
            @endforelse

            <button type="submit" style="margin-top:8px;">Submit Selection</button>
        @elseif ($awaitingType === 'email_code')
            <div style="margin-bottom:8px;">
                <strong>{{ $awaitingPayload['prompt'] ?? 'Enter email code:' }}</strong>
            </div>
            <input type="text" name="code" placeholder="123456" required>
            <button type="submit" style="margin-left:8px;">Submit Code</button>
        @elseif ($awaitingType === 'manual_pause')
            <div style="margin-bottom:8px;">
                <strong>{{ $awaitingPayload['message'] ?? 'Manual action required.' }}</strong>
            </div>
            <textarea name="note" style="width:100%; min-height:120px;" required></textarea>
            <button type="submit" style="margin-top:8px;">Submit Note</button>
        @else
            <div style="margin-bottom:8px;">
                Unknown input type. Submit raw JSON payload to unblock the job.
            </div>
            <textarea name="provided_input_json" style="width:100%; min-height:120px;" required>{"selected_option":"HSBC"}</textarea>
            <button type="submit" style="margin-top:8px;">Submit JSON</button>
        @endif
    </form>
@endif

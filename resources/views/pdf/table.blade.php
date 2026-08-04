<!DOCTYPE html>
<html dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: {{ $direction === 'rtl' ? 'dejavusans' : 'dejavusans' }}, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
        }
        h1 {
            font-size: 16px;
            margin: 0 0 4px;
        }
        .subtitle {
            font-size: 11px;
            color: #555;
            margin: 0 0 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 5px 7px;
            text-align: {{ $direction === 'rtl' ? 'right' : 'left' }};
        }
        th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        tr:nth-child(even) td {
            background-color: #fafafa;
        }
        footer {
            position: fixed;
            bottom: -20px;
            left: 0;
            right: 0;
            font-size: 9px;
            color: #888;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    @if($subtitle)
        <p class="subtitle">{{ $subtitle }}</p>
    @endif

    <table>
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}" style="text-align: center; color: #888;">—</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <footer>{{ $generatedAt }}</footer>
</body>
</html>

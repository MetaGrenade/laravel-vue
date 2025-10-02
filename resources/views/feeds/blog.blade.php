@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<feed xmlns="http://www.w3.org/2005/Atom">
    <title>{{ $title }}</title>
    <id>{{ $selfUrl }}</id>
    <link href="{{ $selfUrl }}" rel="self" />
    <link href="{{ $homeUrl }}" />
    <updated>{{ $updatedAt }}</updated>
    @foreach ($entries as $entry)
        <entry>
            <title>{{ $entry['title'] }}</title>
            <id>{{ $entry['link'] }}</id>
            <link href="{{ $entry['link'] }}" />
            @if (!empty($entry['excerpt']))
                <summary type="html">{{ $entry['excerpt'] }}</summary>
            @endif
            <published>{{ $entry['published_at'] }}</published>
            <updated>{{ $entry['updated_at'] }}</updated>
        </entry>
    @endforeach
</feed>

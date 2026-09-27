{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0">
<channel>
    <title>{{ $page->title }}</title>
    <link>{{ $page->publicUrl() }}</link>
    <description>{{ $page->description ?? $page->title }}</description>
    @foreach ($incidents as $i)
    <item>
        <title>{{ $i->title }}{{ $i->isOpen() ? '' : ' — resolved' }}</title>
        <link>{{ $page->publicUrl() }}</link>
        <guid isPermaLink="false">incident-{{ $i->id }}</guid>
        <pubDate>{{ $i->started_at->toRssString() }}</pubDate>
        <description>{{ $i->cause }} ({{ Fmt::duration($i->durationSeconds()) }})</description>
    </item>
    @endforeach
</channel>
</rss>

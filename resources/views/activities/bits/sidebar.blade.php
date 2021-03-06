@php
use Illuminate\Support\HtmlString;
use App\Models\Activity;

// User flags
$isMember = $user && $user->is_member;

// Prepare collection of details
$baseProperties = [
    'Organisatie' => [optional($activity->role)->title ?? 'Gumbo Millennium', null],
];

// Number of seats
$seats = 'Geen limiet';
if ($activity->seats) {
    $seats = "{$activity->seats} plekken";
    if ($activity->available_seats === 0) {
        $seats .= " (uitverkocht)";
    } else if ($activity->available_seats < $activity->seats) {
        $seats .= " ({$activity->available_seats} beschikbaar)";
    }
}
$baseProperties['Aantal plekken'] = [$seats, null];

// Start date
$startTimestamp = $activity->start_date;
$endTimestamp = $activity->end_date;

// Make some checks
$durationIsLong = $startTimestamp->diffInHours($endTimestamp) > 6;
$durationIsMultiDay = $startTimestamp->day !== $endTimestamp->day;

$startDate = $startTimestamp->isoFormat('D MMM Y');
$startTime = $startTimestamp->isoFormat('HH:mm');
$startDateFull = $startTimestamp->isoFormat('D MMM Y, HH:mm (z)');

// Build data set
$dateData = [
    'Datum' => [$startTimestamp->isoFormat('D MMM Y'), null],
    'Aanvang' => [$startTimestamp->isoFormat('H:mm (z)'), null],
];

$durationTitle = 'Duur';
$durationValue = $startTimestamp->diffAsCarbonInterval($endTimestamp)->forHumans(['parts' => 1]);
if ($durationIsLong && $durationIsMultiDay) {
    $dateData = [
        'Aanvang' => [$startTimestamp->isoFormat('D MMM, HH:mm (z)'), null],
        'Einde' => [$endTimestamp->isoFormat('D MMM, HH:mm (z)'), null]
    ];
} elseif ($durationIsLong) {
    unset($dateData['Duur']);
    $dateData['Einde'] = [$endTimestamp->isoFormat('HH:mm (z)'), null];
}

$hasAnyDiscount = $activity->discount_price !== null;
$hasDiscount = $activity->discount_price > 0;
$hasRestrictedDiscount = $activity->discounts_available !== null;
$hasSoldOutDiscount = $activity->discounts_available === 0;

// Prep pricing info
$priceData = [
    'Prijs' => [Str::price($activity->total_price) ?? 'Gratis', null]
];

if ($hasAnyDiscount) {
    $guestPrice = $priceData['Prijs'][0];
    $memberPrice = Str::price($activity->total_discount_price) ?? 'Gratis';

    $guestLabel = 'Prijs gasten';
    $memberLabel = 'Prijs leden';

    if ($hasRestrictedDiscount && $isMember) {
        // Alter labels
        $memberLabel = "Prijs korting (×{$activity->discount_count})";
        $guestLabel = 'Prijs regulier';

        // Alter values
        if ($hasSoldOutDiscount) {
            $memberPrice .= ' (uitverkocht)';
        }
    } elseif ($hasRestrictedDiscount && $hasDiscount) {
        $memberPrice = sprintf("Vanaf %s", Str::lower($memberPrice));
    } elseif ($hasRestrictedDiscount) {
        $memberPrice = sprintf("Vanaf %s", Str::price(0));
    }

    // Add values
    $priceData = [
        $memberLabel => [$memberPrice, null],
        $guestLabel => [$guestPrice, null]
    ];
}

// Prep location
$location = new HtmlString('<span class="text-gray-primary-1">Onbekend</span>');
$locationIcon = null;
if (!empty($activity->location) && $activity->location_type === Activity::LOCATION_ONLINE) {
    $location = $activity->location;
    $locationIcon = 'solid/globe-europe';
} elseif (!empty($activity->location) && !empty($activity->location_url)) {
    $location = new HtmlString(sprintf(
        '<a href="%s" target="_blank" rel="noopener nofollow">%s</a>',
        e($activity->location_url),
        e($activity->location)
    ));
} elseif (!empty($activity->location)) {
    $location = $activity->location;
}

$locationData = [
    'Locatie' => [$location, $locationIcon]
];

// Bundle properties
$properties = array_merge($baseProperties, $dateData, $priceData, $locationData);

// Tagline
$tagline = $activity->tagline ?? vsprintf('Op %s, van %s tot %s.', [
    $startTimestamp->isoFormat('D MMMM'),
    $startTimestamp->isoFormat('H:mm'),
    $endTimestamp->isoFormat('H:mm'),
]);


// Get link, if any
$nextLink = isset($link) ? $link : 'list';
$isPublic = $activity->is_public;

// Show-hide stuff
$mainTitle ??= false;
$showJoin ??= false;
$showMeta ??= false;
$showTagline ??= true;

$showCovidWarning = Str::contains(
    Str::lower($activity->cancelled_reason ?? $activity->postponed_reason ?? $activity->rescheduled_reason ?? ''),
    ['covid', 'corona', 'covid19', 'coronavirus']
);

@endphp

{{-- Activity title --}}
@if ($mainTitle)
<h1 class="text-3xl font-title {{ $isPublic ? 'mb-4' : 'mb-2' }}">{{ $activity->name }}</h1>
@else
<h2 class="text-2xl font-title {{ $isPublic ? 'mb-4' : 'mb-2' }}">{{ $activity->name }}</h2>
@endif

{{-- Members only message, if required --}}
@if (!$activity->is_public || !$activity->is_published)
<div class="text-gray-primary-1 text-sm font-bold uppercase mb-4 flex flex-row items-center">
    @if (!$activity->is_published)
    <p class="mr-4">
        @icon('solid/eye-slash', 'mr-1')
        verborgen
    </p>
    @endif
    @if (!$activity->is_public)
    <p class="mr-4">
        @icon('solid/lock', 'mr-1')
        alleen voor leden
    </p>
    @endif
</div>
@endif

{{-- Show cancellation prompt if cancelled --}}
@if ($activity->is_cancelled)
<div class="notice notice--large notice--warning">
    <strong class="notice__title">Geannuleerd</strong>
    <p class="m-0 w-full">
        {{ $activity->cancelled_reason ?: 'Deze activiteit is geannuleerd.' }}
    </p>
    @includeWhen($showCovidWarning, 'covid19.block')
</div>
@else
{{-- Description --}}
@if ($activity->end_date < now() && !$activity->is_postponed)
<div class="notice notice--large notice--warning">
    <strong class="notice__title">Activiteit afgelopen</strong>
    <p class="m-0 w-full">
        Deze activiteit is inmiddels afgelopen. Bekijk onze recente activiteiten op
        <a href="{{ route('activity.index') }}">de activiteitenpagina</a>.
    </p>
</div>
@else
@if ($activity->is_rescheduled && !$activity->is_cancelled)
@php
$fromDateIso = $activity->rescheduled_from->toIso8601String();
$fromDate = $activity->rescheduled_from->isoFormat('D MMM Y, HH:mm (z)');
$toDateIso = $activity->start_date->toIso8601String();
$toDate = $activity->start_date->isoFormat('D MMM Y, HH:mm (z)');
@endphp
<div class="notice notice--large notice--warning">
    <strong class="notice__title">Activiteit verplaatst</strong>
    <p class="m-0 w-full">
        @if (!empty($activity->rescheduled_reason))
            {{ $activity->rescheduled_reason }}
        @else
            Deze activiteit is verplaatst van <time class="inline-block" datetime="{{ $fromDateIso }}">{{ $fromDate }}</time>
            naar <time class="inline-block font-bold" datetime="{{ $toDateIso }}">{{ $toDate }}</time>.
        @endif
    </p>
    @includeWhen($showCovidWarning, 'covid19.block')
</div>
@elseif ($activity->is_postponed && !$activity->is_cancelled)
@php
$onDateIso = $activity->postponed_at->toIso8601String();
$onDate = $activity->postponed_at->isoFormat('D MMM Y, HH:mm (z)');
@endphp
<div class="notice notice--large notice--warning">
    <strong class="notice__title">Activiteit uitgesteld</strong>
    <p class="m-0 w-full">
        @if (!empty($activity->postponed_reason))
            {{ $activity->postponed_reason }}
        @else
            Deze activiteit is uitgesteld op <time class="inline-block" datetime="{{ $onDateIso }}">{{ $onDate }}</time>.<br />
            Een nieuwe datum is <strong class="inline-block">nog niet bekend</strong>.
        @endif
    </p>
    @includeWhen($showCovidWarning, 'covid19.block')
</div>
@endif
@if ($showTagline)
    <p class="text-gray-primary-1 mb-4">{{ $tagline }}</p>
@endif
@endif

{{-- Join button --}}
@if ($showJoin)
    {{-- In an if-statement, otherwise "compact" goes kaboohm --}}
    @include('activities.bits.join-button', compact('is_enrolled', 'enrollment'))
@endif
@endif

{{-- Metadata --}}
@if (!empty($details))
{{ $details }}
@endif

@if ($showMeta)
{{-- Make some room --}}
<hr class="border-gray-secondary-3 my-8" />

{{-- Data --}}
<dl class="flex flex-row flex-wrap row">
    @foreach ($properties as $label => list($value, $icon))
    <dt class="col w-1/3 flex-none mb-2 font-bold">{{ $label }}</dt>
    <dd class="col w-2/3 flex-none mb-2 text-sm">
        @if ($icon)
            @icon($icon, 'mr-2')
        @endif
        {{ $value }}
    </dd>
    @endforeach
</dl>

{{-- Make some more room --}}
<hr class="border-gray-secondary-3 my-8" />
@endif

{{-- Back link --}}
@if ($nextLink === 'activity')
<a href="{{ route('activity.show', compact('activity')) }}"
    class="inline-block p-4 mb-4 no-underline p-4 text-sm">
    @icon('chevron-left', 'mr-2')
    Terug naar details
</a>
@elseif ($activity === 'list')
<a href="{{ route('activity.index') }}"
    class="inline-block p-4 mb-4 no-underline p-4 text-sm">
    @icon('chevron-left', 'mr-2')
    Terug naar overzicht
</a>
@endif

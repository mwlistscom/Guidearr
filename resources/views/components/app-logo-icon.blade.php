{{--
    Brand mark used on the auth screens (register / login / password reset).

    Serves the icon uploaded under Admin -> Branding, falling back to the bundled
    default — the same source the signed-in header and sidebar already use, so an
    operator who uploads their icon sees it everywhere rather than the framework's
    mark on the first page a new user ever lands on.

    Callers size this with utility classes (size-9, h-7, …). Colour utilities they
    also pass (fill-current, text-white) are inert on an image and simply ignored.
--}}
@php($brandName = config('app.name', 'Guidearr'))

<img src="{{ route('branding.icon') }}" alt="{{ $brandName }}"
     {{ $attributes->merge(['class' => 'object-contain']) }} />

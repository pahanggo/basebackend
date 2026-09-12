<?php

use Workflow\Support\SafeLocalUrl;

it('returns null for a null or empty value', function () {
    expect(SafeLocalUrl::resolve(null))->toBeNull();
    expect(SafeLocalUrl::resolve(''))->toBeNull();
});

it('returns the url unchanged when it points at this same app', function () {
    $local = url('/app/purchase-requests');

    expect(SafeLocalUrl::resolve($local))->toBe($local);
});

it('returns null for a url pointing at a different host', function () {
    expect(SafeLocalUrl::resolve('https://evil.example.com/phishing'))->toBeNull();
});

it('returns null for a protocol-relative url (still a different host)', function () {
    expect(SafeLocalUrl::resolve('//evil.example.com/phishing'))->toBeNull();
});

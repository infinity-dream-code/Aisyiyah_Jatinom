@extends('errors::minimal')

@section('title', __('Memuat ulang'))
@section('code', '')
@section('message', '')

<script>
    (function () {
        if (document.referrer) {
            window.location.replace(document.referrer);
            return;
        }
        if (window.history.length > 1) {
            window.history.back();
            return;
        }
        window.location.reload();
    })();
</script>

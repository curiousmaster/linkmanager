function setViewCookie(view) {
    var expires = new Date();
    expires.setTime(expires.getTime() + 30*24*60*60*1000);
    document.cookie = "view_mode=" + encodeURIComponent(view) + "; expires=" + expires.toUTCString() + "; path=/; samesite=lax";
}

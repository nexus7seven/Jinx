(function () {
  "use strict";

  var PLAYLIST_ID = "PLuRsK2HoWIJBxfl9tfQ16IuT3R1y2WpV1";
  var PLAYLIST_SIZE = 50;

  var iframe = document.getElementById("yt-player");
  var titleEl = document.getElementById("video-title");
  var btn = document.getElementById("another-btn");

  function pickRandomPlaylistIndex(excludeIndex) {
    if (PLAYLIST_SIZE <= 1) {
      return 0;
    }
    var idx;
    do {
      idx = Math.floor(Math.random() * PLAYLIST_SIZE);
    } while (idx === excludeIndex);
    return idx;
  }

  var lastPlaylistIndex = -1;

  function loadRandomFromPlaylist() {
    var index = pickRandomPlaylistIndex(lastPlaylistIndex);
    lastPlaylistIndex = index;
    var url =
      "https://www.youtube.com/embed?listType=playlist&list=" +
      encodeURIComponent(PLAYLIST_ID) +
      "&index=" +
      index +
      "&autoplay=1&mute=1&rel=0&modestbranding=1";
    iframe.src = url;
    titleEl.textContent =
      "Now playing something random from Noah’s playlist 🎵";
  }

  btn.addEventListener("click", loadRandomFromPlaylist);

  loadRandomFromPlaylist();
})();

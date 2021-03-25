<div class="col-12 animated bounceIn">
    <div class="card mb-1">
        <div style="height:400px" id="map"></div>
    </div>
    <script>
        var mymap = L.map('map', {
            scrollWheelZoom: false,
            center: [3.8187, 103.3341],
            zoom: 14
        });
        L.tileLayer('https://cartodb-basemaps-{s}.global.ssl.fastly.net/light_all/{z}/{x}/{y}.png', {
            attribution: ''
        }).addTo(mymap);
    </script>
</div>

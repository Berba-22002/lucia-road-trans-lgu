const TOMTOM_API_KEY = 'LNpIcTDy0lIJ7onGiR5oEJYyE7Riyh88';
let map = null, marker = null, cameraStream = null, videoStream = null, mediaRecorder = null, recordedChunks = [];
let livePreviewActive = false;

function initMap() {
    if (!map) {
        map = L.map('ovrMap').setView([14.6760, 120.9626], 11);
        L.tileLayer(`https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=${TOMTOM_API_KEY}`, {attribution: '© TomTom'}).addTo(map);
    }
}

async function geocodeAddress(address) {
    if (!address.trim()) { if (marker) map.removeLayer(marker); marker = null; return; }
    try {
        const response = await fetch(`https://api.tomtom.com/search/2/geocode/${encodeURIComponent(address)}.json?key=${TOMTOM_API_KEY}&countrySet=PH`);
        const data = await response.json();
        if (data.results && data.results.length > 0) {
            const result = data.results[0];
            if (marker) map.removeLayer(marker);
            marker = L.marker([result.position.lat, result.position.lon]).addTo(map);
            map.setView([result.position.lat, result.position.lon], 16);
        }
    } catch (error) { console.error('Geocoding error:', error); }
}

document.getElementById('openModalBtn').addEventListener('click', () => {
    document.getElementById('ovrModal').classList.add('active');
    setTimeout(() => initMap(), 100);
});

function closeModal() {
    document.getElementById('ovrModal').classList.remove('active');
    if (cameraStream) cameraStream.getTracks().forEach(t => t.stop());
    if (videoStream) videoStream.getTracks().forEach(t => t.stop());
    livePreviewActive = false;
}

document.getElementById('location').addEventListener('input', function() {
    clearTimeout(window.geocodeTimeout);
    window.geocodeTimeout = setTimeout(() => geocodeAddress(this.value), 1000);
});

document.getElementById('getCurrentLocationBtn').addEventListener('click', function() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(async (position) => {
            const lat = position.coords.latitude, lng = position.coords.longitude;
            try {
                const response = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`);
                const data = await response.json();
                if (data && data.address) {
                    const addr = data.address, parts = [];
                    if (addr.house_number) parts.push(addr.house_number);
                    if (addr.road) parts.push(addr.road);
                    if (addr.suburb) parts.push(addr.suburb);
                    if (addr.city) parts.push(addr.city);
                    if (addr.province) parts.push(addr.province);
                    if (parts.length > 0) {
                        document.getElementById('location').value = parts.join(', ');
                        geocodeAddress(document.getElementById('location').value);
                    }
                }
            } catch (error) { console.error('Error:', error); }
        });
    }
});

document.getElementById('violation_category').addEventListener('change', async function() {
    const category = this.value, select = document.getElementById('violation_id');
    select.innerHTML = '<option value="">Select Violation</option>';
    select.disabled = !category;
    document.getElementById('penalty_amount').value = '';
    document.getElementById('ordinance_code').value = '';
    if (category) {
        try {
            const response = await fetch(`./api/get_violations.php?action=get_violations_by_category&category=${encodeURIComponent(category)}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const violations = await response.json();
            if (Array.isArray(violations)) {
                violations.forEach(v => {
                    const option = document.createElement('option');
                    option.value = v.id;
                    option.textContent = v.violation_name;
                    select.appendChild(option);
                });
            }
        } catch (error) { console.error('Error:', error); }
    }
});

const calculateFine = async function() {
    const violationId = document.getElementById('violation_id').value, offenseCount = document.getElementById('offense_count').value;
    if (violationId && offenseCount) {
        try {
            const response = await fetch(`./api/get_violations.php?action=get_fine&violation_id=${violationId}&offense_count=${offenseCount}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            if (data.fine !== undefined) {
                document.getElementById('penalty_amount').value = data.fine;
                document.getElementById('ordinance_code').value = data.ordinance_code || '';
            }
        } catch (error) { console.error('Error:', error); }
    }
};

document.getElementById('violation_id').addEventListener('change', calculateFine);
document.getElementById('offense_count').addEventListener('change', calculateFine);

document.getElementById('offender_name').addEventListener('input', async function() {
    const query = this.value.trim();
    const suggestionsDiv = document.getElementById('residentSuggestions');
    
    if (query.length < 2) {
        suggestionsDiv.classList.add('hidden');
        document.getElementById('residentInfo').classList.add('hidden');
        document.getElementById('resident_id').value = '';
        return;
    }
    
    try {
        const response = await fetch(`../api/search_residents.php?q=${encodeURIComponent(query)}`);
        const residents = await response.json();
        
        if (residents.length > 0) {
            suggestionsDiv.innerHTML = residents.map(r => `
                <div class="px-4 py-2 hover:bg-gray-100 cursor-pointer border-b text-sm" onclick="selectResident('${r.id}', '${r.fullname}')">
                    <div class="font-semibold text-lgu-headline">${r.fullname}</div>
                    <div class="text-xs text-lgu-paragraph">ID: ${r.id}</div>
                </div>
            `).join('');
            suggestionsDiv.classList.remove('hidden');
        } else {
            suggestionsDiv.innerHTML = '<div class="px-4 py-2 text-sm text-lgu-paragraph italic">No residents found - you can enter a custom name</div>';
            suggestionsDiv.classList.remove('hidden');
            document.getElementById('residentInfo').classList.add('hidden');
            document.getElementById('resident_id').value = '';
        }
    } catch (error) {
        console.error('Search error:', error);
    }
});

document.getElementById('offender_name').addEventListener('blur', function() {
    const suggestionsDiv = document.getElementById('residentSuggestions');
    setTimeout(() => suggestionsDiv.classList.add('hidden'), 200);
});

function selectResident(id, name) {
    document.getElementById('offender_name').value = name;
    document.getElementById('resident_id').value = id;
    document.getElementById('residentIdDisplay').textContent = id;
    document.getElementById('residentInfo').classList.remove('hidden');
    document.getElementById('residentSuggestions').classList.add('hidden');
}

function viewTicket(ticket) {
    Swal.fire({
        title: 'Ticket Details',
        html: `<div class="text-left text-sm space-y-2"><p><strong>Ticket #:</strong> ${ticket.ticket_number}</p><p><strong>Violation:</strong> ${ticket.violation_name}</p><p><strong>Offender:</strong> ${ticket.offender_name}</p><p><strong>Fine:</strong> ₱${parseFloat(ticket.penalty_amount).toFixed(2)}</p><p><strong>Date:</strong> ${ticket.violation_date} ${ticket.violation_time}</p><p><strong>Location:</strong> ${ticket.location}</p></div>`,
        confirmButtonColor: '#faae2b'
    });
}


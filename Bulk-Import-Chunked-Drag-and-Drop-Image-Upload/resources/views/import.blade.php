<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Bulk Import & Image Upload - {{ config('app.name', 'Laravel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 min-h-screen py-8">
    <div class="max-w-6xl mx-auto px-4">
        <h1 class="text-3xl font-bold mb-8">Bulk Import & Image Upload</h1>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- CSV Import Section -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-semibold mb-4">CSV Import</h2>
                <form id="csvImportForm" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label for="csvFile" class="block text-sm font-medium text-gray-700 mb-2">
                            Select CSV File
                        </label>
                        <input type="file" id="csvFile" name="file" accept=".csv,.txt" 
                               class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" required>
                    </div>
                    <button type="submit" id="csvImportBtn" 
                            class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 disabled:bg-gray-400 disabled:cursor-not-allowed">
                        Import CSV
                    </button>
                </form>
                <div id="csvResult" class="mt-4 hidden"></div>
            </div>

            <!-- Image Upload Section -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-xl font-semibold mb-4">Image Upload</h2>
                <div id="dropZone" 
                     class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center cursor-pointer hover:border-blue-400 transition-colors">
                    <p class="text-gray-600 mb-2">Drag and drop images here</p>
                    <p class="text-sm text-gray-500">or</p>
                    <label for="imageFile" class="mt-2 inline-block bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 cursor-pointer">
                        Browse Files
                    </label>
                    <input type="file" id="imageFile" name="file" accept="image/*" multiple class="hidden">
                </div>
                <div id="uploadProgress" class="mt-4 space-y-2"></div>
            </div>
        </div>

        <!-- Products List -->
        <div class="mt-8 bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-semibold mb-4">Products</h2>
            <div id="productsList" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SKU</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                        </tr>
                    </thead>
                    <tbody id="productsTableBody" class="bg-white divide-y divide-gray-200">
                        <!-- Products will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Configure axios with CSRF token
        axios.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        // CSV Import
        document.getElementById('csvImportForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData();
            const fileInput = document.getElementById('csvFile');
            const btn = document.getElementById('csvImportBtn');
            const resultDiv = document.getElementById('csvResult');

            if (!fileInput.files[0]) {
                alert('Please select a CSV file');
                return;
            }

            formData.append('file', fileInput.files[0]);
            btn.disabled = true;
            btn.textContent = 'Importing...';
            resultDiv.classList.add('hidden');

            try {
                const response = await axios.post('/api/csv/import', formData, {
                    headers: { 'Content-Type': 'multipart/form-data' }
                });

                if (response.data.success) {
                    const result = response.data.result;
                    resultDiv.innerHTML = `
                        <div class="bg-green-50 border border-green-200 rounded-md p-4">
                            <h3 class="font-semibold text-green-800 mb-2">Import Completed</h3>
                            <ul class="text-sm text-green-700 space-y-1">
                                <li>Total: ${result.total}</li>
                                <li>Imported: ${result.imported}</li>
                                <li>Updated: ${result.updated}</li>
                                <li>Invalid: ${result.invalid}</li>
                                <li>Duplicates: ${result.duplicates}</li>
                            </ul>
                        </div>
                    `;
                    resultDiv.classList.remove('hidden');
                    loadProducts();
                } else {
                    throw new Error(response.data.message || 'Import failed');
                }
            } catch (error) {
                resultDiv.innerHTML = `
                    <div class="bg-red-50 border border-red-200 rounded-md p-4">
                        <p class="text-red-800">Error: ${error.response?.data?.message || error.message}</p>
                    </div>
                `;
                resultDiv.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Import CSV';
                fileInput.value = '';
            }
        });

        // Chunked Image Upload
        const CHUNK_SIZE = 1024 * 1024; // 1MB
        const uploads = new Map();

        document.getElementById('imageFile').addEventListener('change', (e) => {
            Array.from(e.target.files).forEach(file => {
                handleFileUpload(file);
            });
        });

        const dropZone = document.getElementById('dropZone');
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('border-blue-500');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('border-blue-500');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-blue-500');
            Array.from(e.dataTransfer.files).forEach(file => {
                if (file.type.startsWith('image/')) {
                    handleFileUpload(file);
                }
            });
        });

        async function handleFileUpload(file) {
            const uploadId = generateUUID();
            const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
            
            // Calculate checksum
            const checksum = await calculateSHA256(file);

            // Initialize upload
            try {
                const initResponse = await axios.post('/api/upload/init', {
                    filename: file.name,
                    total_size: file.size,
                    mime_type: file.type,
                    checksum: checksum,
                    total_chunks: totalChunks,
                });

                if (!initResponse.data.success) {
                    throw new Error('Failed to initialize upload');
                }

                const uploadId = initResponse.data.upload_id;
                uploads.set(uploadId, { file, progress: 0 });

                // Create progress UI
                const progressDiv = createProgressUI(uploadId, file.name);
                document.getElementById('uploadProgress').appendChild(progressDiv);

                // Upload chunks
                await uploadChunks(uploadId, file, totalChunks, checksum);

            } catch (error) {
                console.error('Upload failed:', error);
                alert(`Upload failed: ${error.message}`);
            }
        }

        async function uploadChunks(uploadId, file, totalChunks, checksum) {
            const progressBar = document.querySelector(`[data-upload-id="${uploadId}"] .progress-bar`);
            const statusText = document.querySelector(`[data-upload-id="${uploadId}"] .status-text`);

            for (let i = 0; i < totalChunks; i++) {
                const start = i * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);

                const chunkFormData = new FormData();
                chunkFormData.append('upload_id', uploadId);
                chunkFormData.append('chunk_index', i);
                chunkFormData.append('chunk', chunk);

                try {
                    const response = await axios.post('/api/upload/chunk', chunkFormData, {
                        headers: { 'Content-Type': 'multipart/form-data' },
                        onUploadProgress: (progressEvent) => {
                            const chunkProgress = (progressEvent.loaded / progressEvent.total) * 100;
                            const overallProgress = ((i + chunkProgress / 100) / totalChunks) * 100;
                            progressBar.style.width = `${overallProgress}%`;
                        }
                    });

                    if (response.data.success) {
                        statusText.textContent = `Uploaded ${response.data.chunks_received}/${response.data.total_chunks} chunks`;
                        
                        if (response.data.complete) {
                            // Complete upload
                            await completeUpload(uploadId);
                        }
                    }
                } catch (error) {
                    console.error(`Chunk ${i} upload failed:`, error);
                    // Retry logic could be added here
                    throw error;
                }
            }
        }

        async function completeUpload(uploadId) {
            const statusText = document.querySelector(`[data-upload-id="${uploadId}"] .status-text`);
            statusText.textContent = 'Processing image...';

            try {
                const response = await axios.post('/api/upload/complete', {
                    upload_id: uploadId,
                });

                if (response.data.success) {
                    statusText.textContent = 'Upload completed!';
                    statusText.classList.add('text-green-600');
                    loadProducts();
                } else {
                    throw new Error(response.data.message || 'Completion failed');
                }
            } catch (error) {
                statusText.textContent = `Error: ${error.message}`;
                statusText.classList.add('text-red-600');
            }
        }

        function createProgressUI(uploadId, filename) {
            const div = document.createElement('div');
            div.className = 'border rounded-lg p-4';
            div.setAttribute('data-upload-id', uploadId);
            div.innerHTML = `
                <div class="flex justify-between mb-2">
                    <span class="text-sm font-medium">${filename}</span>
                    <span class="status-text text-sm text-gray-600">Initializing...</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="progress-bar bg-blue-600 h-2 rounded-full transition-all" style="width: 0%"></div>
                </div>
            `;
            return div;
        }

        async function calculateSHA256(file) {
            const buffer = await file.arrayBuffer();
            const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        }

        function generateUUID() {
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }

        async function loadProducts() {
            try {
                const response = await axios.get('/api/products');
                const tbody = document.getElementById('productsTableBody');
                tbody.innerHTML = '';

                response.data.forEach(product => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${product.sku}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${product.name}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${product.price || '-'}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${product.quantity}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            ${product.primary_image ? `<img src="/storage/${product.primary_image.path}" class="h-16 w-16 object-cover rounded">` : '-'}
                        </td>
                    `;
                    tbody.appendChild(row);
                });
            } catch (error) {
                console.error('Failed to load products:', error);
            }
        }

        // Load products on page load
        loadProducts();
    </script>
</body>
</html>


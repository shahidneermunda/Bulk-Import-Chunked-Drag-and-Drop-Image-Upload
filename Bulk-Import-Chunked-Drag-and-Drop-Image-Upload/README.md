# Bulk Import + Chunked Drag-and-Drop Image Upload

A Laravel application implementing bulk CSV import and chunked/resumable image uploads with automatic variant generation.

## Features

### CSV Import
- Bulk import products from CSV files
- Upsert functionality (create new or update existing products by SKU)
- Comprehensive result summary (total, imported, updated, invalid, duplicates)
- Invalid rows are skipped but don't stop the import process
- Duplicate detection within CSV files

### Chunked Image Upload
- Drag-and-drop image upload interface
- Chunked/resumable uploads (1MB chunks)
- SHA256 checksum validation
- Automatic resume capability
- Idempotent chunk uploads (re-sending chunks won't corrupt data)

### Image Processing
- Automatic variant generation (256px, 512px, 1024px)
- Aspect ratio preservation
- Multiple image format support (JPEG, PNG, GIF, WebP)

### Database Schema
- **Products**: Unique by SKU, with optional primary image reference
- **Uploads**: Tracks chunked upload sessions with status and progress
- **Images**: Stores image metadata, variants, and checksums

## Installation

1. Install dependencies:
```bash
composer install
npm install
```

2. Set up environment:
```bash
cp .env.example .env
php artisan key:generate
```

3. Run migrations:
```bash
php artisan migrate
```

4. Create storage link:
```bash
php artisan storage:link
```

5. Build frontend assets:
```bash
npm run build
```

## Usage

### Access the Application

Visit `/import` in your browser to access the bulk import and image upload interface.

### CSV Import Format

The CSV file should have the following columns:
- `sku` (required): Unique product identifier
- `name` (required): Product name
- `description` (optional): Product description
- `price` (optional): Product price (numeric)
- `quantity` (optional): Product quantity (integer)

Example CSV:
```csv
sku,name,description,price,quantity
SKU001,Product 1,Description 1,10.50,100
SKU002,Product 2,Description 2,20.00,200
```

### Image Upload

1. Drag and drop images onto the upload zone, or click "Browse Files"
2. Images are automatically uploaded in chunks
3. Upload progress is displayed in real-time
4. After completion, variants are automatically generated
5. Images can be attached to products via the API

### API Endpoints

#### CSV Import
- `POST /api/csv/import` - Import products from CSV file

#### Image Upload
- `POST /api/upload/init` - Initialize a new chunked upload
- `POST /api/upload/chunk` - Upload a chunk
- `POST /api/upload/complete` - Complete the upload and process image
- `GET /api/upload/status` - Get upload status
- `POST /api/upload/attach` - Attach image to product

#### Products
- `GET /api/products` - Get all products with images

## Testing

Run the test suite:
```bash
php artisan test
```

### Unit Tests

- **CsvImportServiceTest**: Tests upsert logic, invalid row handling, and duplicate detection
- **ImageProcessingServiceTest**: Tests variant generation and aspect ratio preservation

## Requirements Met

✅ **Domain**: Products (unique by SKU)  
✅ **CSV Import**: Upsert by SKU with result summary  
✅ **Chunked Upload**: Resume support, checksum validation, idempotent chunks  
✅ **Image Variants**: 256px, 512px, 1024px with aspect ratio preservation  
✅ **Database**: Upload and Image records with product linking  
✅ **Error Handling**: Invalid rows don't stop import, checksum validation blocks completion  
✅ **Idempotency**: Re-attaching same image to same product is no-op  
✅ **Concurrency**: Database transactions ensure data integrity  
✅ **Unit Tests**: Tests for upsert logic and image processing  

## Technical Details

### Concurrency Safety
- Database transactions ensure atomic operations
- Unique constraints prevent duplicate SKUs
- Chunk tracking prevents duplicate chunk processing

### Image Processing
- Uses PHP GD extension for image manipulation
- Variants are stored in `storage/app/public/images/variants/`
- Original images stored in `storage/app/public/images/`

### Chunked Upload Flow
1. Client calculates file checksum (SHA256)
2. Initialize upload session with metadata
3. Upload chunks sequentially (idempotent)
4. Server assembles chunks into final file
5. Verify file size and checksum
6. Generate image variants
7. Create Image record with variant paths

## Notes

- Requires PHP GD extension for image processing
- Large CSV files (>10,000 rows) are supported
- Image uploads support hundreds of images
- All file operations are transaction-safe

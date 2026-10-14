<?php

namespace Tests\Feature\Admin\Catalogue;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductFileManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        config(['commerce.downloads.disk' => 'local', 'commerce.downloads.max_per_product' => 20, 'commerce.downloads.max_kilobytes' => 102400]);
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function upload(Product $product, array $files, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin())->post(route('acp.commerce.files.store', $product), ['files' => $files] + $extra);
    }

    // --- Adding ------------------------------------------------------------------------------------

    #[Test]
    public function a_file_is_uploaded_and_described(): void
    {
        $product = Product::factory()->digital()->create();

        $this->upload($product, [UploadedFile::fake()->createWithContent('manual.pdf', 'pdf bytes here')])
            ->assertRedirect()->assertSessionHas('success', 'File added.')->assertSessionHasNoErrors();

        $file = ProductFile::sole();

        $this->assertSame($product->id, $file->product_id);
        $this->assertSame('manual.pdf', $file->name, 'labelled by its own name until renamed');
        $this->assertSame('manual.pdf', $file->original_name);
        $this->assertSame(14, $file->size);
        $this->assertSame(hash('sha256', 'pdf bytes here'), $file->sha256);
        $this->assertTrue($file->is_active);
        $this->assertSame('pdf bytes here', Storage::disk('local')->get($file->path));
    }

    #[Test]
    public function files_are_kept_privately_under_a_generated_name(): void
    {
        $product = Product::factory()->digital()->create();

        $this->upload($product, [UploadedFile::fake()->createWithContent('Totally Evil.php', '<?php echo 1;')]);

        $file = ProductFile::sole();

        Storage::disk('local')->assertExists($file->path);
        Storage::disk('public')->assertMissing($file->path);
        $this->assertSame('local', $file->disk);
        $this->assertMatchesRegularExpression('#^product-files/'.$product->id.'/[0-9a-z]{26}$#', $file->path, 'no extension and nothing the uploader typed');
        $this->assertStringNotContainsString('evil', strtolower($file->path));
    }

    #[Test]
    public function a_public_disk_is_refused_because_anyone_could_fetch_the_files(): void
    {
        config(['commerce.downloads.disk' => 'public']);
        $product = Product::factory()->digital()->create();

        $this->upload($product, [UploadedFile::fake()->createWithContent('a.zip', 'x')])
            ->assertSessionHasErrors(['files' => 'The download disk "public" is public, so anyone could fetch these files. Set COMMERCE_DOWNLOAD_DISK to a private disk.']);

        $this->assertSame(0, ProductFile::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function the_name_a_file_is_saved_as_is_cleaned(): void
    {
        $product = Product::factory()->digital()->create();

        $this->upload($product, [UploadedFile::fake()->createWithContent('../../etc/passwd', 'x')]);

        $this->assertSame('passwd', ProductFile::sole()->original_name, 'no directories');
    }

    #[Test]
    public function the_type_recorded_is_what_the_server_sees_not_what_the_browser_says(): void
    {
        $product = Product::factory()->digital()->create();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        // Claimed to be a PDF by its name and its declared type; it is a PNG. (A real upload object:
        // Laravel's fake ones report a type guessed from the name.)
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $png);
        $claimed = new UploadedFile($path, 'report.pdf', 'application/pdf', null, true);

        $this->upload($product, [$claimed]);

        $this->assertSame('image/png', ProductFile::sole()->mime);
        $this->assertSame('report.pdf', ProductFile::sole()->original_name);

        @unlink($path);
    }

    #[Test]
    public function several_files_can_be_added_at_once(): void
    {
        $product = Product::factory()->digital()->create();

        $this->upload($product, [
            UploadedFile::fake()->createWithContent('a.zip', 'aaa'),
            UploadedFile::fake()->createWithContent('b.zip', 'bbbb'),
        ])->assertSessionHas('success', '2 files added.');

        $this->assertSame(['a.zip', 'b.zip'], ProductFile::query()->orderBy('position')->pluck('name')->all());
        $this->assertSame([1, 2], ProductFile::query()->orderBy('position')->pluck('position')->all());
    }

    #[Test]
    public function a_single_file_can_be_given_its_own_label(): void
    {
        $product = Product::factory()->digital()->create();

        $this->upload($product, [UploadedFile::fake()->createWithContent('v3-final.zip', 'x')], ['name' => 'The complete course']);

        $this->assertSame('The complete course', ProductFile::sole()->name);
        $this->assertSame('v3-final.zip', ProductFile::sole()->original_name);
    }

    #[Test]
    public function a_product_can_only_have_so_many_files(): void
    {
        config(['commerce.downloads.max_per_product' => 2]);
        $product = Product::factory()->digital()->create();
        ProductFile::factory()->for($product)->count(2)->create();

        $this->upload($product, [UploadedFile::fake()->createWithContent('third.zip', 'x')])
            ->assertSessionHasErrors('files');

        $this->assertSame(2, ProductFile::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'the file just uploaded was not kept');
    }

    #[Test]
    public function a_file_over_the_limit_is_refused_before_anything_is_stored(): void
    {
        config(['commerce.downloads.max_kilobytes' => 1]);
        $product = Product::factory()->digital()->create();

        $this->upload($product, [UploadedFile::fake()->create('big.bin', 5)])
            ->assertSessionHasErrors(['files.0' => 'Each file can be at most 0 MB.']);

        $this->assertSame(0, ProductFile::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function something_must_be_chosen(): void
    {
        $product = Product::factory()->digital()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.files.store', $product), [])->assertSessionHasErrors(['files' => 'Choose a file to upload.']);
        $this->post(route('acp.commerce.files.store', $product), ['files' => 'not a file'])->assertSessionHasErrors('files');
    }

    // --- Changing ----------------------------------------------------------------------------------

    #[Test]
    public function a_file_can_be_renamed_and_switched_off(): void
    {
        $file = ProductFile::factory()->create(['name' => 'Old']);

        $this->actingAs($this->admin())->put(route('acp.commerce.files.update', $file), ['name' => 'New name', 'is_active' => false])
            ->assertRedirect()->assertSessionHas('success');

        $file->refresh();
        $this->assertSame('New name', $file->name);
        $this->assertFalse($file->is_active);
    }

    #[Test]
    public function a_file_needs_a_name_and_a_switch(): void
    {
        $file = ProductFile::factory()->create();

        $this->actingAs($this->admin())->put(route('acp.commerce.files.update', $file), ['name' => '', 'is_active' => true])->assertSessionHasErrors('name');
        $this->put(route('acp.commerce.files.update', $file), ['name' => 'x'])->assertSessionHasErrors('is_active');
        $this->put(route('acp.commerce.files.update', $file), ['name' => str_repeat('a', 256), 'is_active' => true])->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_file_can_be_replaced_keeping_its_label(): void
    {
        $product = Product::factory()->digital()->create();
        $file = ProductFile::factory()->for($product)->withContents('version one')->create(['name' => 'The course', 'original_name' => 'course-v1.zip']);
        $oldPath = $file->path;

        $this->actingAs($this->admin())->post(route('acp.commerce.files.replace', $file), ['file' => UploadedFile::fake()->createWithContent('course-v2.zip', 'version two!')])
            ->assertRedirect()->assertSessionHas('success', 'File replaced.');

        $file->refresh();

        $this->assertSame('The course', $file->name);
        $this->assertSame('course-v2.zip', $file->original_name);
        $this->assertSame(12, $file->size);
        $this->assertSame(hash('sha256', 'version two!'), $file->sha256);
        $this->assertNotSame($oldPath, $file->path);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame('version two!', Storage::disk('local')->get($file->path));
        $this->assertSame(1, ProductFile::query()->count(), 'the same file, not a new one');
    }

    #[Test]
    public function a_replacement_must_be_a_file_within_the_limit(): void
    {
        config(['commerce.downloads.max_kilobytes' => 1]);
        $file = ProductFile::factory()->withContents('keep me')->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.files.replace', $file), [])->assertSessionHasErrors('file');
        $this->post(route('acp.commerce.files.replace', $file), ['file' => UploadedFile::fake()->create('big.bin', 5)])->assertSessionHasErrors('file');

        $this->assertSame('keep me', Storage::disk('local')->get($file->fresh()->path), 'the old file is untouched');
    }

    #[Test]
    public function deleting_a_file_deletes_it_from_the_disk(): void
    {
        $file = ProductFile::factory()->withContents('bye')->create();
        $path = $file->path;

        $this->actingAs($this->admin())->delete(route('acp.commerce.files.destroy', $file))->assertRedirect()->assertSessionHas('success');

        $this->assertModelMissing($file);
        Storage::disk('local')->assertMissing($path);
    }

    #[Test]
    public function deleting_a_product_deletes_its_files_too(): void
    {
        $product = Product::factory()->digital()->create();
        $file = ProductFile::factory()->for($product)->withContents('gone')->create();

        $this->actingAs($this->admin())->delete(route('acp.commerce.products.destroy', $product))->assertRedirect();

        $this->assertModelMissing($product);
        $this->assertSame(0, ProductFile::query()->count());
        Storage::disk('local')->assertMissing($file->path);
    }

    #[Test]
    public function a_product_that_was_ordered_keeps_its_files(): void
    {
        $product = Product::factory()->digital()->create();
        $file = ProductFile::factory()->for($product)->withContents('kept')->create();
        Order::factory()->create()->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.00', 'subtotal' => '10.00', 'description' => 'Ebook']);

        $this->actingAs($this->admin())->delete(route('acp.commerce.products.destroy', $product))->assertSessionHas('error');

        $this->assertModelExists($file);
        Storage::disk('local')->assertExists($file->path);
    }

    // --- Access ------------------------------------------------------------------------------------

    #[Test]
    public function each_action_has_its_own_permission(): void
    {
        $file = ProductFile::factory()->create();
        $viewer = User::factory()->create()->assignRole('editor');
        $viewer->givePermissionTo('commerce.acp.view');
        $upload = fn () => [UploadedFile::fake()->createWithContent('a.zip', 'x')];

        $this->actingAs($viewer)->post(route('acp.commerce.files.store', $file->product), ['files' => $upload()])->assertForbidden();
        $this->put(route('acp.commerce.files.update', $file), ['name' => 'x', 'is_active' => true])->assertForbidden();
        $this->post(route('acp.commerce.files.replace', $file), ['file' => $upload()[0]])->assertForbidden();
        $this->delete(route('acp.commerce.files.destroy', $file))->assertForbidden();

        $viewer->givePermissionTo('commerce.acp.create');
        $this->post(route('acp.commerce.files.store', $file->product), ['files' => $upload()])->assertRedirect();
        $this->put(route('acp.commerce.files.update', $file), ['name' => 'x', 'is_active' => true])->assertForbidden();

        $viewer->givePermissionTo(['commerce.acp.edit', 'commerce.acp.delete']);
        $this->put(route('acp.commerce.files.update', $file), ['name' => 'x', 'is_active' => true])->assertRedirect();
        $this->delete(route('acp.commerce.files.destroy', $file))->assertRedirect();
    }

    #[Test]
    public function the_product_page_lists_the_files_and_the_rules(): void
    {
        config(['commerce.downloads.limit' => 5, 'commerce.downloads.expires_after_days' => 90]);
        $product = Product::factory()->digital()->create();
        $file = ProductFile::factory()->for($product)->create(['name' => 'Manual', 'size' => 2048]);

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))->assertInertia(fn (Assert $page) => $page
            ->where('files.0.id', $file->id)
            ->where('files.0.name', 'Manual')
            ->where('files.0.size', 2048)
            ->where('files.0.is_active', true)
            ->missing('files.0.path')
            ->missing('files.0.disk')
            ->where('file_rules.limit', 5)
            ->where('file_rules.expires_after_days', 90)
            ->where('file_rules.max_count', 20));
    }
}

<?php

namespace Tests\Feature\Admin\Catalogue;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\Commerce\Catalogue\CatalogueException;
use App\Support\Commerce\Catalogue\ProductImages;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class ProductImagesTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RolePermissionSeeder::class);
        $this->setUpCommerce();
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * @param  list<string>  $permissions
     */
    private function staffWith(array $permissions): User
    {
        $user = User::factory()->create()->assignRole('editor');
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * The messages left in the session for a field after the last request.
     *
     * @return list<string>
     */
    private function errorsFor(string $field): array
    {
        return session('errors')?->get($field) ?? [];
    }

    private function upload(string $name = 'photo.jpg', int $width = 1200, int $height = 800): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    /**
     * Put a real picture on a product, through the service.
     */
    private function addPicture(Product $product, ?string $alt = null): ProductImage
    {
        return app(ProductImages::class)->add($product, $this->upload(), $alt);
    }

    // --- Adding -----------------------------------------------------------------------------------

    #[Test]
    public function a_picture_is_added_and_kept_at_three_sizes(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload()]])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Picture added.');

        $image = $product->images()->sole();
        $this->assertSame('public', $image->disk);
        $this->assertSame(0 + 1, $image->position);
        $this->assertSame([1200, 800], [$image->width, $image->height]);
        $this->assertGreaterThan(0, $image->bytes);
        $this->assertStringStartsWith("products/{$product->id}/", $image->path);
        $this->assertStringEndsWith('-large.webp', $image->path);

        foreach ($image->paths() as $path) {
            Storage::disk('public')->assertExists($path);
        }
        $this->assertCount(3, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function the_stored_files_are_new_pictures_not_the_upload(): void
    {
        $product = Product::factory()->create();
        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload('holiday.jpg', 3000, 2000)]]);

        $image = $product->images()->sole();

        $this->assertStringNotContainsString('holiday', $image->path, 'the uploaded name is not used');
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring(Storage::disk('public')->get($image->path))[2]);
        $this->assertSame(1600, getimagesizefromstring(Storage::disk('public')->get($image->path))[0]);
        $this->assertSame(320, getimagesizefromstring(Storage::disk('public')->get($image->thumb_path))[0]);
    }

    #[Test]
    public function several_pictures_are_added_in_order(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), [
            'images' => [$this->upload('a.jpg'), $this->upload('b.png', 600, 600), $this->upload('c.jpg')],
        ])->assertSessionHas('success', '3 pictures added.');

        $this->assertSame([1, 2, 3], $product->images()->pluck('position')->all());
    }

    #[Test]
    public function a_new_picture_goes_after_the_ones_already_there(): void
    {
        $product = Product::factory()->create();
        $first = $this->addPicture($product);

        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload()]]);

        $this->assertSame($first->id, $product->images()->first()->id);
        $this->assertSame(2, $product->images()->count());
    }

    #[Test]
    public function a_description_is_saved_with_the_upload_and_trimmed(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload()], 'alt' => '  Front view  '])
            ->assertSessionHasNoErrors();

        $this->assertSame('Front view', $product->images()->sole()->alt);
    }

    #[Test]
    public function the_upload_is_validated(): void
    {
        $product = Product::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.images.store', $product), [])->assertSessionHasErrors(['images' => 'Choose a picture to upload.']);
        $this->post(route('acp.commerce.images.store', $product), ['images' => 'not-a-file'])->assertSessionHasErrors('images');
        $this->post(route('acp.commerce.images.store', $product), ['images' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])
            ->assertSessionHasErrors(['images.0' => 'Upload a JPEG, PNG, WebP or GIF picture.']);
        $this->post(route('acp.commerce.images.store', $product), ['images' => [UploadedFile::fake()->create('page.html', 1, 'text/html')]])
            ->assertSessionHasErrors('images.0');
        $this->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload()], 'alt' => str_repeat('a', 256)])->assertSessionHasErrors('alt');

        $this->assertSame(0, ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_file_over_the_size_limit_is_refused(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), ['images' => [UploadedFile::fake()->image('big.jpg')->size(6000)]])
            ->assertSessionHasErrors(['images.0' => 'Each picture can be at most 5 MB.']);

        $this->assertSame(0, ProductImage::count());
    }

    #[Test]
    public function something_that_is_not_a_picture_is_refused_whatever_it_is_called(): void
    {
        $product = Product::factory()->create();
        $disguised = UploadedFile::fake()->createWithContent('photo.jpg', '<?php system($_GET["c"]); ?>');

        // The file-type rule goes by what the file looks like and lets this through; decoding it as a
        // picture (the real check) refuses it.
        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), ['images' => [$disguised]])
            ->assertSessionHasErrors('images');

        $this->assertSame(['photo.jpg: Upload a JPEG, PNG, WebP or GIF picture.'], $this->errorsFor('images'));

        $this->assertSame(0, ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_file_with_a_picture_header_that_is_not_one_is_refused_and_the_rest_are_kept(): void
    {
        $product = Product::factory()->create();
        $png = $this->upload('good.png', 400, 400)->get();
        $truncated = UploadedFile::fake()->createWithContent('cut-off.png', substr($png, 0, 60));

        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload('first.jpg'), $truncated, $this->upload('last.jpg')]])
            ->assertSessionHas('success', '2 pictures added.')
            ->assertSessionHasErrors('images');

        $this->assertCount(1, $this->errorsFor('images'));
        $this->assertStringStartsWith('cut-off.png:', $this->errorsFor('images')[0]);
        $this->assertStringContainsString('could not be read', $this->errorsFor('images')[0]);

        $this->assertSame(2, $product->images()->count());
        $this->assertCount(6, Storage::disk('public')->allFiles(), 'three files each for the two that were kept');
    }

    #[Test]
    public function a_picture_with_too_many_pixels_is_refused(): void
    {
        config(['commerce.images.max_pixels' => 100_000]);
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload('huge.jpg', 1000, 1000)]])
            ->assertSessionHasErrors('images');

        $this->assertStringContainsString('megapixels', $this->errorsFor('images')[0]);

        $this->assertSame(0, ProductImage::count());
    }

    #[Test]
    public function a_product_can_only_have_so_many_pictures(): void
    {
        config(['commerce.images.max_per_product' => 2]);
        $product = Product::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload('1.jpg'), $this->upload('2.jpg')]])->assertSessionHas('success');

        // One more is refused with a reason, and nothing is written for it.
        $this->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload('3.jpg')]])
            ->assertSessionHasErrors('images');
        $this->assertStringContainsString('at most 2 pictures', $this->errorsFor('images')[0]);

        $this->assertSame(2, $product->images()->count());
        $this->assertCount(6, Storage::disk('public')->allFiles());

        // And more at once than the limit is refused outright.
        $this->post(route('acp.commerce.images.store', Product::factory()->create()), ['images' => [$this->upload('a.jpg'), $this->upload('b.jpg'), $this->upload('c.jpg')]])
            ->assertSessionHasErrors('images');
    }

    #[Test]
    public function files_are_not_left_behind_when_the_row_cannot_be_saved(): void
    {
        $product = Product::factory()->create();
        $product->delete(); // The row the picture would belong to is gone.

        try {
            app(ProductImages::class)->add($product, $this->upload());
            $this->fail('A picture was added to a product that does not exist.');
        } catch (\Throwable) {
            // Expected: the foreign key refuses it.
        }

        $this->assertSame([], Storage::disk('public')->allFiles(), 'the files that were written are removed again');
        $this->assertSame(0, ProductImage::count());
    }

    #[Test]
    public function the_pictures_go_to_the_configured_disk(): void
    {
        config(['commerce.images.disk' => 'local']);
        Storage::fake('local');
        $product = Product::factory()->create();

        $this->actingAs($this->admin())->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload()]])->assertSessionHasNoErrors();

        $image = $product->images()->sole();
        $this->assertSame('local', $image->disk);
        Storage::disk('local')->assertExists($image->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // --- Describing, arranging, deleting ----------------------------------------------------------

    #[Test]
    public function a_picture_can_be_described_and_the_description_cleared(): void
    {
        $image = $this->addPicture(Product::factory()->create());
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('acp.commerce.images.update', $image), ['alt' => 'Side view'])->assertSessionHas('success');
        $this->assertSame('Side view', $image->fresh()->alt);

        $this->put(route('acp.commerce.images.update', $image), ['alt' => ''])->assertSessionHasNoErrors();
        $this->assertNull($image->fresh()->alt);

        $this->put(route('acp.commerce.images.update', $image), ['alt' => str_repeat('a', 256)])->assertSessionHasErrors('alt');
    }

    #[Test]
    public function the_pictures_can_be_rearranged(): void
    {
        $product = Product::factory()->create();
        [$a, $b, $c] = [$this->addPicture($product), $this->addPicture($product), $this->addPicture($product)];

        $this->actingAs($this->admin())->post(route('acp.commerce.images.reorder', $product), ['ids' => [$c->id, $a->id, $b->id]])
            ->assertSessionHas('success', 'Order saved.');

        $this->assertSame([$c->id, $a->id, $b->id], $product->images()->pluck('id')->all());
        $this->assertSame([0, 1, 2], $product->images()->pluck('position')->all());
        $this->assertSame($c->id, $product->primaryImage()->first()->id, 'the first is the main picture');
    }

    #[Test]
    public function an_order_that_loses_or_invents_a_picture_is_refused(): void
    {
        $product = Product::factory()->create();
        [$a, $b] = [$this->addPicture($product), $this->addPicture($product)];
        $foreign = $this->addPicture(Product::factory()->create());
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('acp.commerce.images.reorder', $product), ['ids' => [$a->id]])->assertSessionHas('error');
        $this->post(route('acp.commerce.images.reorder', $product), ['ids' => [$a->id, $b->id, $foreign->id]])->assertSessionHas('error');
        $this->post(route('acp.commerce.images.reorder', $product), ['ids' => [$a->id, $foreign->id]])->assertSessionHas('error');
        $this->post(route('acp.commerce.images.reorder', $product), ['ids' => [$a->id, $a->id]])->assertSessionHasErrors('ids.1');
        $this->post(route('acp.commerce.images.reorder', $product), ['ids' => []])->assertSessionHasErrors('ids');

        $this->assertSame([$a->id, $b->id], $product->images()->pluck('id')->all());
        $this->assertSame(1, $foreign->fresh()->position, 'another product is untouched');
    }

    #[Test]
    public function a_picture_can_be_made_the_main_one(): void
    {
        $product = Product::factory()->create();
        [$a, $b, $c] = [$this->addPicture($product), $this->addPicture($product), $this->addPicture($product)];

        $this->actingAs($this->admin())->post(route('acp.commerce.images.main', $c))->assertSessionHas('success', 'Main picture changed.');

        $this->assertSame([$c->id, $a->id, $b->id], $product->images()->pluck('id')->all(), 'the others keep their order');
    }

    #[Test]
    public function deleting_a_picture_removes_its_files_and_closes_the_gap(): void
    {
        $product = Product::factory()->create();
        [$a, $b, $c] = [$this->addPicture($product), $this->addPicture($product), $this->addPicture($product)];
        $keep = $this->addPicture(Product::factory()->create());
        $paths = $b->paths();

        $this->actingAs($this->admin())->delete(route('acp.commerce.images.destroy', $b))->assertSessionHas('success', 'Picture deleted.');

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
        $this->assertSame([$a->id, $c->id], $product->images()->pluck('id')->all());
        $this->assertSame([0, 1], $product->images()->pluck('position')->all());
        $this->assertCount(9, Storage::disk('public')->allFiles(), 'the other pictures keep their files');
        Storage::disk('public')->assertExists($keep->path);
    }

    #[Test]
    public function deleting_a_product_deletes_its_picture_files_too(): void
    {
        $product = Product::factory()->create();
        $this->addPicture($product);
        $this->addPicture($product);
        $other = $this->addPicture(Product::factory()->create());

        $this->actingAs($this->admin())->delete(route('acp.commerce.products.destroy', $product))->assertSessionHas('success');

        $this->assertSame(0, ProductImage::where('product_id', $product->id)->count());
        $this->assertCount(3, Storage::disk('public')->allFiles(), 'only the other product keeps its files');
        Storage::disk('public')->assertExists($other->path);
    }

    #[Test]
    public function a_product_that_cannot_be_deleted_keeps_its_pictures(): void
    {
        [, , $product] = $this->placeOrder();
        $image = $this->addPicture($product);

        $this->actingAs($this->admin())->delete(route('acp.commerce.products.destroy', $product))->assertSessionHas('error');

        Storage::disk('public')->assertExists($image->path);
        $this->assertSame(1, $product->images()->count());
    }

    // --- The product page and list ----------------------------------------------------------------

    #[Test]
    public function the_product_page_lists_the_pictures_with_the_rules(): void
    {
        $product = Product::factory()->create();
        $image = $this->addPicture($product, 'Front');

        $this->actingAs($this->admin())->get(route('acp.commerce.products.edit', $product))->assertInertia(fn (Assert $page) => $page
            ->has('images', 1)
            ->where('images.0.id', $image->id)
            ->where('images.0.alt', 'Front')
            ->where('images.0.url', $image->mediumUrl())
            ->where('images.0.thumb', $image->thumbUrl())
            ->where('images.0.width', 1200)
            ->where('image_rules', ['max_count' => 12, 'max_kilobytes' => 5120]));
    }

    #[Test]
    public function the_product_list_shows_each_products_main_picture(): void
    {
        $with = Product::factory()->create(['name' => 'A with']);
        $this->addPicture($with);
        $second = $this->addPicture($with);
        app(ProductImages::class)->makeMain($second);
        Product::factory()->create(['name' => 'B without']);

        $this->actingAs($this->admin())->get(route('acp.commerce.products.index'))->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.image', $second->thumbUrl())
            ->where('products.data.1.image', null));
    }

    // --- Permissions ------------------------------------------------------------------------------

    #[Test]
    public function each_picture_action_has_its_own_permission(): void
    {
        $product = Product::factory()->create();
        $image = $this->addPicture($product);
        $editor = $this->staffWith(['commerce.acp.view', 'commerce.acp.edit']);

        // Editing may describe, arrange and choose the main one...
        $this->actingAs($editor)->put(route('acp.commerce.images.update', $image), ['alt' => 'Fine'])->assertSessionHasNoErrors();
        $this->post(route('acp.commerce.images.main', $image))->assertSessionHasNoErrors();
        $this->post(route('acp.commerce.images.reorder', $product), ['ids' => [$image->id]])->assertSessionHasNoErrors();

        // ...but not upload or delete.
        $this->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload()]])->assertForbidden();
        $this->delete(route('acp.commerce.images.destroy', $image))->assertForbidden();

        $viewer = $this->staffWith(['commerce.acp.view']);
        $this->actingAs($viewer)->put(route('acp.commerce.images.update', $image), ['alt' => 'No'])->assertForbidden();

        $this->assertSame(1, $product->images()->count());
        $this->assertSame('Fine', $image->fresh()->alt);
    }

    #[Test]
    public function guests_cannot_touch_pictures(): void
    {
        $product = Product::factory()->create();
        $image = $this->addPicture($product);

        $this->post(route('acp.commerce.images.store', $product), ['images' => [$this->upload()]])->assertRedirect(route('login'));
        $this->delete(route('acp.commerce.images.destroy', $image))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_service_refuses_the_picture_over_the_limit_without_processing_it(): void
    {
        config(['commerce.images.max_per_product' => 1]);
        $product = Product::factory()->create();
        $this->addPicture($product);

        $this->expectException(CatalogueException::class);
        $this->expectExceptionMessage('at most 1 pictures');

        app(ProductImages::class)->add($product, $this->upload());
    }
}

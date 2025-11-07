<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Supplier::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->company(),
            'address' => $this->faker->streetAddress(),
            'address2' => $this->faker->secondaryAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->stateAbbr(),
            'zip' => $this->faker->postCode(),
            'country' => $this->faker->countryCode(),
            'contact' => $this->faker->name(),
            'phone' => $this->faker->phoneNumber(),
            'fax'   => $this->faker->phoneNumber(),
            'email' => $this->faker->safeEmail(),
            'url'   => $this->faker->url(),
            'notes' => $this->faker->text(191), // Supplier notes can be a max of 255 characters.
        ];
    }

    public function microsoft()
    {
        return $this->state(function () {
            return [
                'name' => 'Microsoft',
                'address' => 'One Microsoft Way',
                'city' => 'Redmond',
                'state' => 'WA',
                'zip' => '98052',
                'country' => 'US',
                'phone' => '+1-425-882-8080',
                'email' => 'support@microsoft.com',
                'url' => 'https://www.microsoft.com',
                'notes' => 'Technology manufacturer and software company',
            ];
        });
    }

    public function apple()
    {
        return $this->state(function () {
            return [
                'name' => 'Apple Inc.',
                'address' => 'One Apple Park Way',
                'city' => 'Cupertino',
                'state' => 'CA',
                'zip' => '95014',
                'country' => 'US',
                'phone' => '+1-408-996-1010',
                'email' => 'support@apple.com',
                'url' => 'https://www.apple.com',
                'notes' => 'Technology manufacturer and electronics company',
            ];
        });
    }

    public function dell()
    {
        return $this->state(function () {
            return [
                'name' => 'Dell Technologies',
                'address' => 'One Dell Way',
                'city' => 'Round Rock',
                'state' => 'TX',
                'zip' => '78682',
                'country' => 'US',
                'phone' => '+1-800-289-3355',
                'email' => 'support@dell.com',
                'url' => 'https://www.dell.com',
                'notes' => 'Computer technology manufacturer',
            ];
        });
    }

    public function lenovo()
    {
        return $this->state(function () {
            return [
                'name' => 'Lenovo',
                'address' => '8001 Development Drive',
                'city' => 'Morrisville',
                'state' => 'NC',
                'zip' => '27560',
                'country' => 'US',
                'phone' => '+1-855-253-6686',
                'email' => 'support@lenovo.com',
                'url' => 'https://www.lenovo.com',
                'notes' => 'Computer and electronics manufacturer',
            ];
        });
    }

    public function asus()
    {
        return $this->state(function () {
            return [
                'name' => 'ASUS',
                'address' => '48720 Kato Road',
                'city' => 'Fremont',
                'state' => 'CA',
                'zip' => '94538',
                'country' => 'US',
                'phone' => '+1-888-678-3688',
                'email' => 'support@asus.com',
                'url' => 'https://www.asus.com',
                'notes' => 'Computer hardware and electronics manufacturer',
            ];
        });
    }

    public function hp()
    {
        return $this->state(function () {
            return [
                'name' => 'HP Inc.',
                'address' => '1501 Page Mill Road',
                'city' => 'Palo Alto',
                'state' => 'CA',
                'zip' => '94304',
                'country' => 'US',
                'phone' => '+1-650-857-1501',
                'email' => 'support@hp.com',
                'url' => 'https://www.hp.com',
                'notes' => 'Computer and printer manufacturer',
            ];
        });
    }

    public function bestBuy()
    {
        return $this->state(function () {
            return [
                'name' => 'Best Buy',
                'address' => '7601 Penn Avenue South',
                'city' => 'Richfield',
                'state' => 'MN',
                'zip' => '55423',
                'country' => 'US',
                'phone' => '+1-888-237-8289',
                'email' => 'customercare@bestbuy.com',
                'url' => 'https://www.bestbuy.com',
                'notes' => 'Consumer electronics retailer',
            ];
        });
    }

    public function target()
    {
        return $this->state(function () {
            return [
                'name' => 'Target',
                'address' => '1000 Nicollet Mall',
                'city' => 'Minneapolis',
                'state' => 'MN',
                'zip' => '55403',
                'country' => 'US',
                'phone' => '+1-800-440-0680',
                'email' => 'guestservices@target.com',
                'url' => 'https://www.target.com',
                'notes' => 'General merchandise retailer',
            ];
        });
    }

    public function microCenter()
    {
        return $this->state(function () {
            return [
                'name' => 'Micro Center',
                'address' => '4119 Leap Road',
                'city' => 'Hilliard',
                'state' => 'OH',
                'zip' => '43026',
                'country' => 'US',
                'phone' => '+1-614-850-3675',
                'email' => 'webcustomerservice@microcenter.com',
                'url' => 'https://www.microcenter.com',
                'notes' => 'Computer and electronics retailer',
            ];
        });
    }
}

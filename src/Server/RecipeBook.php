<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server;

use PhpMinecraftJavaServer\Protocol\Binary;

/**
 * ServerRecipeBook: the recipes each player knows, those still highlighted as new, and the
 * recipe book's open and filtering settings for each menu. Recipes are shown through the display
 * entries the official RecipeManager builds, which recipe-book.json holds already encoded with
 * their RecipeDisplayId.
 */
trait RecipeBook
{
    /** RecipeBookType order, as the settings packet and the client's change requests use it. */
    private const RECIPE_BOOK_SETTINGS = [
        ['isGuiOpen', 'isFilteringCraftable'],
        ['isFurnaceGuiOpen', 'isFurnaceFilteringCraftable'],
        ['isBlastingFurnaceGuiOpen', 'isBlastingFurnaceFilteringCraftable'],
        ['isSmokerGuiOpen', 'isSmokerFilteringCraftable'],
    ];

    /**
     * @return array{displays: list<array{string, bool, string}>, byRecipe: array<string, list<int>>, learnable: list<string>, recipes: array<string, int>, updateRecipes: string}
     */
    private function recipeBookReference(): array
    {
        static $reference = null;
        if ($reference === null) {
            $data = json_decode((string) file_get_contents($this->version->directory . '/recipe-book.json'), true, 512, JSON_THROW_ON_ERROR);
            $displays = [];
            $byRecipe = [];
            foreach ($data['displays'] as $id => $display) {
                $displays[$id] = [(string) hex2bin($display['entry']), (bool) $display['notification'], $display['recipe']];
                $byRecipe[$display['recipe']][] = $id;
            }
            $reference = ['displays' => $displays, 'byRecipe' => $byRecipe, 'learnable' => $data['learnable'], 'recipes' => array_flip($data['recipes']), 'updateRecipes' => (string) hex2bin($data['update_recipes'])];
        }
        return $reference;
    }

    /** RecipeManager.getLearnableRecipes: every recipe but the special ones, in the manager's order. */
    private function learnableRecipes(): array
    {
        return $this->recipeBookReference()['learnable'];
    }

    /** ResourceKeyArgument.getRecipe: the recipe, unless it is special or unknown. */
    private function isLearnableRecipe(string $id): bool
    {
        static $learnable = null;
        $learnable ??= array_flip($this->learnableRecipes());
        return isset($learnable[$id]);
    }

    /**
     * PlayerAdvancements: recipes/decorations/crafting_table is the one recipe advancement whose
     * criterion, minecraft:tick, any player meets; it completes on the first tick and rewards the
     * crafting table recipe once, so taking the recipe later does not bring it back.
     */
    private function tickRecipeAdvancements(Client $c): void
    {
        $advancement = 'minecraft:recipes/decorations/crafting_table';
        if (!isset($c->advancements[$advancement])) {
            $c->advancements[$advancement] = true;
            $this->awardRecipes($c, ['minecraft:crafting_table']);
        }
    }

    /** ServerRecipeBook.addRecipes: learn the recipes not yet known; the count is of display entries sent. */
    private function awardRecipes(Client $c, array $recipes): int
    {
        $reference = $this->recipeBookReference();
        $entries = [];
        foreach ($recipes as $id) {
            if (isset($c->knownRecipes[$id]) || !$this->isLearnableRecipe($id)) {
                continue;
            }
            $c->knownRecipes[$id] = true;
            $c->highlightedRecipes[$id] = true;
            foreach ($reference['byRecipe'][$id] ?? [] as $display) {
                [$entry, $notification] = $reference['displays'][$display];
                $entries[] = $entry . chr(($notification ? 1 : 0) | 2);
            }
        }
        if ($entries !== []) {
            $c->send($this->version, 'recipe_book_add', Binary::writeVarInt(count($entries)) . implode('', $entries) . "\0");
        }
        return count($entries);
    }

    /** ServerRecipeBook.removeRecipes: forget the known recipes; the count is of display entries removed. */
    private function resetRecipes(Client $c, array $recipes): int
    {
        $removed = [];
        foreach ($recipes as $id) {
            if (!isset($c->knownRecipes[$id])) {
                continue;
            }
            unset($c->knownRecipes[$id], $c->highlightedRecipes[$id]);
            array_push($removed, ...($this->recipeBookReference()['byRecipe'][$id] ?? []));
        }
        if ($removed !== []) {
            $c->send($this->version, 'recipe_book_remove', Binary::writeVarInt(count($removed)) . implode('', array_map(Binary::writeVarInt(...), $removed)));
        }
        return count($removed);
    }

    /** PlayerList.placeNewPlayer: the item property sets and stonecutter recipes the client needs. */
    private function sendUpdateRecipes(Client $c): void
    {
        $c->send($this->version, 'update_recipes', $this->recipeBookReference()['updateRecipes']);
    }

    /** ServerRecipeBook.sendInitialRecipeBook: the settings, then every known recipe, replacing the client's. */
    private function sendInitialRecipeBook(Client $c): void
    {
        $c->send($this->version, 'recipe_book_settings', implode('', array_map(static fn (bool $value): string => $value ? "\1" : "\0", $c->recipeBookSettings)));
        $reference = $this->recipeBookReference();
        $entries = [];
        foreach ($c->knownRecipes as $id => $_) {
            foreach ($reference['byRecipe'][$id] ?? [] as $display) {
                $entries[] = $reference['displays'][$display][0] . (isset($c->highlightedRecipes[$id]) ? "\2" : "\0");
            }
        }
        $c->send($this->version, 'recipe_book_add', Binary::writeVarInt(count($entries)) . implode('', $entries) . "\1");
    }

    /** ServerGamePacketListenerImpl.handleRecipeBookSeenRecipePacket: the recipe is no longer new. */
    private function recipeSeen(Client $c, int $display): void
    {
        $recipe = $this->recipeBookReference()['displays'][$display][2] ?? null;
        if ($recipe !== null) {
            unset($c->highlightedRecipes[$recipe]);
        }
    }

    /** ServerGamePacketListenerImpl.handleRecipeBookChangeSettingsPacket. */
    private function changeRecipeBookSettings(Client $c, int $type, bool $open, bool $filtering): void
    {
        if ($type >= 0 && $type < 4) {
            $c->recipeBookSettings[$type * 2] = $open;
            $c->recipeBookSettings[$type * 2 + 1] = $filtering;
        }
    }

    /** ServerRecipeBook.Packed as saved: the settings set, the known recipes and those to highlight. */
    private function savedRecipeBook(Client $c): array
    {
        $saved = [];
        foreach (self::RECIPE_BOOK_SETTINGS as $type => $names) {
            foreach ($names as $offset => $name) {
                if ($c->recipeBookSettings[$type * 2 + $offset]) {
                    $saved[$name] = true;
                }
            }
        }
        return [...$saved, 'recipes' => array_keys($c->knownRecipes), 'toBeDisplayed' => array_keys($c->highlightedRecipes)];
    }

    /** ServerRecipeBook.loadUntrusted: recipes the server no longer has are dropped. */
    private function loadRecipeBook(Client $c, array $saved): void
    {
        $recipes = $this->recipeBookReference()['recipes'];
        foreach (self::RECIPE_BOOK_SETTINGS as $type => $names) {
            foreach ($names as $offset => $name) {
                $c->recipeBookSettings[$type * 2 + $offset] = (bool) ($saved[$name] ?? false);
            }
        }
        foreach (['recipes' => 'knownRecipes', 'toBeDisplayed' => 'highlightedRecipes'] as $key => $field) {
            $c->$field = [];
            foreach ($saved[$key] ?? [] as $id) {
                if (!is_string($id) || !isset($recipes[$id])) {
                    $this->log('Tried to load unrecognized recipe: ' . (is_string($id) ? $id : get_debug_type($id)) . ' removed now.');
                    continue;
                }
                $c->$field[$id] = true;
            }
        }
    }
}

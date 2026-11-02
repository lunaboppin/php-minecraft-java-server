<?php

declare(strict_types=1);

namespace PhpMinecraftJavaServer\Server\Commands;

use PhpMinecraftJavaServer\Server\Client;

trait RecipeCommand
{
    /**
     * RecipeCommand: unlock or take away one learnable recipe, or all of them, for players. The
     * counts are of the recipe book entries sent; CommandResponseTracker names the one player whose
     * book changed, or counts them.
     */
    private function executeRecipe(Client $sender, array $arguments): void
    {
        [$action, $selector, $recipe] = $arguments;
        $players = $this->selectPlayers($selector, $sender);
        if ($recipe === '*') {
            $recipes = $this->learnableRecipes();
        } else {
            $id = str_contains($recipe, ':') ? $recipe : 'minecraft:' . $recipe;
            if (!$this->isLearnableRecipe($id)) {
                throw new CommandFailure(['translate' => 'recipe.notFound', 'with' => [['text' => $id]]]);
            }
            $recipes = [$id];
        }
        $total = 0;
        $changed = [];
        foreach ($players as $player) {
            $count = $action === 'give' ? $this->awardRecipes($player, $recipes) : $this->resetRecipes($player, $recipes);
            $total += $count;
            if ($count !== 0) {
                $changed[] = $player;
            }
        }
        if ($changed === []) {
            throw new CommandFailure(['translate' => 'commands.recipe.' . $action . '.failed']);
        }
        $this->sendSuccess($sender, count($changed) === 1 ? ['translate' => 'commands.recipe.' . $action . '.success.single', 'with' => [$total, $this->displayName($changed[0])]]
            : ['translate' => 'commands.recipe.' . $action . '.success.multiple', 'with' => [$total, count($changed)]], true);
    }
}

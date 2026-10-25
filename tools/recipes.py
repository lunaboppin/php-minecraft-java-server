"""Compile ordinary vanilla crafting and cooking recipes with resolved item-tag ingredients."""
import json
import zipfile


def compile_recipes(jar, item_ids):
    with zipfile.ZipFile(jar) as archive:
        tags = {name[len('data/minecraft/tags/item/'):-5]: json.loads(archive.read(name))['values']
                for name in archive.namelist() if name.startswith('data/minecraft/tags/item/') and name.endswith('.json')}
        cache = {}
        def ingredient(value):
            if isinstance(value, list):
                return sorted(set(i for entry in value for i in ingredient(entry)))
            if isinstance(value, dict):
                return ingredient(value.get('item', '#' + value['tag'] if 'tag' in value else value.get('id', '')))
            if value.startswith('#'):
                name = value[1:].removeprefix('minecraft:')
                if name not in cache:
                    cache[name] = []
                    cache[name] = sorted(set(i for entry in tags.get(name, []) for i in ingredient(entry)))
                return cache[name]
            item = item_ids.get(value.removeprefix('minecraft:'))
            return [] if item is None else [item]
        recipes = []
        for name in sorted(archive.namelist()):
            if not name.startswith('data/minecraft/recipe/') or not name.endswith('.json'):
                continue
            source = json.loads(archive.read(name))
            kind = source.get('type', '').removeprefix('minecraft:')
            result = source.get('result', {})
            if isinstance(result, str):
                result = {'id': result}
            if 'id' not in result or result.get('components'):
                continue
            item = item_ids.get(result['id'].removeprefix('minecraft:'))
            if item is None:
                continue
            recipe = {'name': name[len('data/minecraft/recipe/'):-5], 'type': kind,
                      'result': {'item': item, 'count': result.get('count', 1)}}
            if kind == 'crafting_shaped':
                pattern = source['pattern']
                recipe.update(width=len(pattern[0]), height=len(pattern), ingredients=[ingredient(source['key'][c]) if c != ' ' else [] for row in pattern for c in row])
            elif kind == 'crafting_shapeless':
                recipe['ingredients'] = [ingredient(entry) for entry in source['ingredients']]
            elif kind in ('smelting', 'blasting', 'smoking'):
                recipe.update(ingredients=[ingredient(source['ingredient'])], ticks=source.get('cookingtime', 200), experience=source.get('experience', 0))
            else:
                continue
            recipes.append(recipe)
        return recipes

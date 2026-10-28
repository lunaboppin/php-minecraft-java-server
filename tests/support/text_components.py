"""Independent encoder for vanilla NBT text components, used to check PHP output byte-for-byte."""
import struct


# Independent of the PHP encoder: vanilla NBT text components. CompoundTag is a Java HashMap,
# so fields appear in bucket order (the official join message in tests/text-component-unit.php
# pins this against captured bytes).
def nbt_string(value):
    data = value.encode('utf-8')
    return struct.pack('>H', len(data)) + data


def java_order(keys):
    def bucket(key):
        value = 0
        for unit in struct.unpack('>%dH' % len(key), key.encode('utf-16-be')):
            value = (31 * value + unit) & 0xffffffff
        return (value ^ (value >> 16)) & 15
    return sorted(keys, key=bucket)


def body(component):
    out = b''
    for key in java_order(list(component)):
        value = component[key]
        if isinstance(value, bool):
            out += b'\x01' + nbt_string(key) + bytes([int(value)])
        elif key == 'uuid':
            out += b'\x0b' + nbt_string(key) + struct.pack('>i', 4) + value
        elif isinstance(value, str):
            out += b'\x08' + nbt_string(key) + nbt_string(value)
        elif isinstance(value, dict):
            out += b'\x0a' + nbt_string(key) + body(value)
        elif all(list(c) == ['text'] for c in value):
            out += b'\x09' + nbt_string(key) + b'\x08' + struct.pack('>i', len(value)) + b''.join(nbt_string(c['text']) for c in value)
        else:
            # Mixed tag types: ListTag writes compounds and wraps each bare string as {"": value}.
            entries = [b'\x08' + nbt_string('') + nbt_string(c['text']) + b'\0' if list(c) == ['text'] else body(c) for c in value]
            out += b'\x09' + nbt_string(key) + b'\x0a' + struct.pack('>i', len(value)) + b''.join(entries)
    return out + b'\0'


def component(value):
    """A complete component tag: text-only components are bare strings."""
    return b'\x08' + nbt_string(value['text']) if list(value) == ['text'] else b'\x0a' + body(value)


def player(name, uuid):
    return {'text': name, 'insertion': name, 'click_event': {'action': 'suggest_command', 'command': '/tell ' + name + ' '},
            'hover_event': {'action': 'show_entity', 'id': 'minecraft:player', 'uuid': uuid, 'name': name}}


def chat(message):
    """A system_chat payload: the component and a false overlay flag."""
    return component(message) + b'\0'


def translate(key, *arguments):
    return {'translate': key, 'with': list(arguments)} if arguments else {'translate': key}


def admin(sender, message):
    return {'translate': 'chat.type.admin', 'with': [sender, message], 'color': 'gray', 'italic': True}

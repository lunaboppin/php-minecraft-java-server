"""Independent position reconstruction for valid protocol-777 entity movement."""
import math
import struct

from protocol import wire


def coordinate(value):
    value *= 4096.0
    lower = math.floor(value)
    return lower + int(value - lower >= .5)


class EntityPositions:
    def __init__(self):
        self.positions = {}
        self.ground = {}

    def absolute_path(self, data, offset):
        mode, offset = wire.read_vi(data, offset)
        if mode == 0:
            return list(struct.unpack_from('>ddd', data, offset)), offset + 24
        if mode != 1:
            raise ValueError('Unsupported position path type')
        count, offset = wire.read_vi(data, offset)
        if not 1 <= count <= (len(data) - offset) // 25:
            raise ValueError('Invalid absolute position step count')
        for _ in range(count):
            position = list(struct.unpack_from('>ddd', data, offset))
            _, offset = wire.read_vi(data, offset + 24)
        return position, offset

    def apply(self, name, data):
        if name not in ['add_entity', 'entity_position_sync', 'move_entity_pos', 'move_entity_pos_rot', 'move_entity_rot', 'teleport_entity', 'remove_entities']:
            return
        entity, offset = wire.read_vi(data)
        if name == 'remove_entities':
            for _ in range(entity):
                removed, offset = wire.read_vi(data, offset)
                self.positions.pop(removed, None)
                self.ground.pop(removed, None)
        elif name == 'add_entity':
            _, offset = wire.read_vi(data, offset + 16)
            self.positions[entity] = list(struct.unpack_from('>ddd', data, offset))
            offset += 24
            header = data[offset]
            offset += 1
            if header != 0:
                offset += 5
                if header & 4:
                    _, offset = wire.read_vi(data, offset)
            _, offset = wire.read_vi(data, offset + 3)
        elif name == 'entity_position_sync':
            self.positions[entity], offset = self.absolute_path(data, offset)
            offset += 8  # yaw/pitch are floats, not quantized angle bytes.
            self.ground[entity] = bool(data[offset])
            offset += 1
        elif name == 'teleport_entity':
            position = list(struct.unpack_from('>ddd', data, offset))
            flags = struct.unpack_from('>I', data, offset + 56)[0]
            if flags:
                raise ValueError('This observer does not interpret relative teleport flags')
            self.positions[entity] = position
            self.ground[entity] = bool(data[offset + 60])
            offset += 61
        elif name == 'move_entity_rot':
            self.ground[entity] = bool(data[offset])
            offset += 3
        else:
            flags, offset = wire.read_vi(data, offset)
            count = flags >> 1
            self.ground[entity] = bool(flags & 1)
            if entity not in self.positions:
                raise ValueError('Relative movement arrived before entity pairing')
            if count > (len(data) - offset) // 7:
                raise ValueError('Invalid relative position step count')
            for _ in range(count or 1):
                if count:
                    _, offset = wire.read_vi(data, offset)
                delta = struct.unpack_from('>hhh', data, offset)
                offset += 6
                self.positions[entity] = [
                    base if change == 0 else (coordinate(base) + change) / 4096.0
                    for base, change in zip(self.positions[entity], delta)
                ]
            if name == 'move_entity_pos_rot':
                offset += 2
        if offset != len(data):
            raise ValueError(f'Trailing or truncated {name} position data: {offset}/{len(data)}')

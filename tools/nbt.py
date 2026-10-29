"""Read binary NBT."""

import struct


class Nbt:
    """A minimal reader for uncompressed NBT: region chunks and structure templates."""

    def __init__(self, data):
        self.data = data
        self.offset = 0

    def take(self, size):
        chunk = self.data[self.offset:self.offset + size]
        self.offset += size
        return chunk

    def read(self, tag):
        if tag == 1:
            return struct.unpack('>b', self.take(1))[0]
        if tag == 2:
            return struct.unpack('>h', self.take(2))[0]
        if tag == 3:
            return struct.unpack('>i', self.take(4))[0]
        if tag == 4:
            return struct.unpack('>q', self.take(8))[0]
        if tag == 5:
            return struct.unpack('>f', self.take(4))[0]
        if tag == 6:
            return struct.unpack('>d', self.take(8))[0]
        if tag == 7:
            size = struct.unpack('>i', self.take(4))[0]
            return self.take(size)
        if tag == 8:
            size = struct.unpack('>H', self.take(2))[0]
            return self.take(size).decode('utf-8', 'replace')
        if tag == 9:
            inner = self.take(1)[0]
            size = struct.unpack('>i', self.take(4))[0]
            return [self.read(inner) for _ in range(size)]
        if tag == 10:
            out = {}
            while True:
                inner = self.take(1)[0]
                if inner == 0:
                    return out
                name = self.read(8)
                out[name] = self.read(inner)
        if tag == 11:
            size = struct.unpack('>i', self.take(4))[0]
            return list(struct.unpack('>%di' % size, self.take(4 * size)))
        if tag == 12:
            size = struct.unpack('>i', self.take(4))[0]
            return list(struct.unpack('>%dq' % size, self.take(8 * size)))
        raise ValueError('Unknown NBT tag %d' % tag)

    def root(self):
        tag = self.take(1)[0]
        self.read(8)
        return self.read(tag)

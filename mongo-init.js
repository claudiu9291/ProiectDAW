db = db.getSiblingDB('agrolocal');

db.createUser({
  user: 'agro_user',
  pwd: 'AgroPass!2026#Sec',
  roles: [{ role: 'readWrite', db: 'agrolocal' }]
});

db.createCollection('farmers');
db.createCollection('products', {
  validator: {
    $jsonSchema: {
      bsonType: 'object',
      required: ['name', 'slug', 'base_price', 'final_price', 'stock', 'image_filename'],
      properties: {
        name: { bsonType: 'string' },
        slug: { bsonType: 'string' },
        base_price: { bsonType: ['double', 'int'], minimum: 0.01 },
        final_price: { bsonType: ['double', 'int'], minimum: 0.01 },
        stock: { bsonType: 'int', minimum: 0 },
        eco_certificate: { bsonType: ['string', 'null'] },
        image_filename: { bsonType: 'string' }
      }
    }
  }
});
db.createCollection('orders');

const farmerId = new ObjectId();
db.farmers.insertOne({
  _id: farmerId,
  farm_name: 'Ferma Bio Hărman',
  email: 'contact@fermaharman.ro',
  county: 'Brașov',
  created_at: new Date()
});

db.products.insertMany([
  {
    farmer_id: farmerId,
    name: 'Miere Bio de Salcâm',
    slug: 'miere-bio-de-salcam',
    base_price: 45.00,
    final_price: 42.75,
    stock: NumberInt(30),
    eco_certificate: 'RO-ECO-451',
    image_filename: 'default_honey.jpg',
    created_at: new Date()
  },
  {
    farmer_id: farmerId,
    name: 'Roșii Inimă de Bou',
    slug: 'rosii-inima-de-bou',
    base_price: 20.00,
    final_price: 19.00,
    stock: NumberInt(100),
    eco_certificate: 'RO-ECO-102',
    image_filename: 'default_tomatoes.jpg',
    created_at: new Date()
  }
]);

-- TABEL PELABUHAN
CREATE TABLE ports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL,
    name VARCHAR(100) NOT NULL,
    location VARCHAR(100)
);

-- TABEL JETTY / DERMAGA
CREATE TABLE jetties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    port_id INT NOT NULL,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    max_draft_meters DECIMAL(5,2),
    FOREIGN KEY (port_id) REFERENCES ports(id) ON DELETE CASCADE
);

-- TABEL MASTER KAPAL
CREATE TABLE vessels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    imo_number VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    vessel_type VARCHAR(50),
    flag VARCHAR(50)
);

-- TABEL KUNJUNGAN & OPERASIONAL (Hierarki Waiting List & Berthing)
CREATE TABLE vessel_operations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    port_id INT NOT NULL,
    vessel_id INT NOT NULL,
    jetty_id INT DEFAULT NULL,
    main_status VARCHAR(20) NOT NULL, -- 'WAITING_LIST' atau 'BERTHING'
    operation_type VARCHAR(20) DEFAULT NULL, -- 'LOADING' atau 'UNLOADING'
    cargo_type VARCHAR(100),
    target_volume_tons DECIMAL(12,2),
    current_volume_tons DECIMAL(12,2) DEFAULT 0.00,
    FOREIGN KEY (port_id) REFERENCES ports(id),
    FOREIGN KEY (vessel_id) REFERENCES vessels(id),
    FOREIGN KEY (jetty_id) REFERENCES jetties(id)
);

-- MEMASUKKAN DATA CONTOH (SAMPLE DATA)
INSERT INTO ports (code, name, location) VALUES ('IDTPP', 'Pelabuhan Utama Tanjung Priok', 'Jakarta');

INSERT INTO jetties (port_id, code, name, max_draft_meters) VALUES 
(1, 'JTY-A', 'Jetty A - High Flow', 14.5),
(1, 'JTY-B', 'Jetty B - General Cargo', 12.0),
(1, 'JTY-C', 'Jetty C - Liquid Bulk', 10.5);

INSERT INTO vessels (imo_number, name, vessel_type, flag) VALUES
('IMO9123456', 'MV Nusantara Satu', 'Bulk Carrier', 'Indonesia'),
('IMO9234567', 'MV Pacific Explorer', 'Container Ship', 'Panama'),
('IMO9345678', 'TB Power Trans 08', 'Tug & Barge', 'Indonesia');

INSERT INTO vessel_operations (port_id, vessel_id, jetty_id, main_status, operation_type, cargo_type, target_volume_tons, current_volume_tons) VALUES 
(1, 3, NULL, 'WAITING_LIST', NULL, 'CPO / Palm Oil', 12000.00, 0.00),
(1, 1, 1, 'BERTHING', 'LOADING', 'Batubara / Coal', 45000.00, 31500.00),
(1, 2, 2, 'BERTHING', 'UNLOADING', 'Kargo Semen', 20000.00, 8000.00);